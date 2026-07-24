<?php

namespace App\Commands\Secrets;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithSecrets;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\ResolvesToolHost;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;

class SecretsInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithClusterContext, InteractsWithPlex, InteractsWithSecrets, LaraKubeOutput, ResolvesToolEnvironment, ResolvesToolHost, StreamsProcessOutput;

    protected $signature = 'secrets:init
        {environment? : Environment this install targets — "local" (default) or a cloud env. Omit to be prompted. A non-local env prompts for + persists the Infisical host.}
        {--admin-email=    : Override the auto-generated admin email}
        {--admin-password= : Override the auto-generated admin password}
        {--context=        : Target a specific kube-context (defaults to current context)}
        {--domain=         : Base domain OR full host for Infisical (example.com → secrets.example.com; secrets.example.com used as-is)}
        {--no-plex         : Bypass Plex Commons and deploy dedicated database/cache pods instead}
        {--no-bootstrap    : Skip auto-bootstrapping and do it manually via the web UI}
        {--vpn-only        : Restrict access via NetBird VPN IP whitelisting}
        {--force           : Skip the confirmation prompt}';

    protected $description = 'Deploy the cluster-wide Infisical secrets management stack';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deploySecrets();
    }

    protected function deploySecrets(): int
    {
        $env = $this->resolveEnvironment();
        $context = $this->resolveToolContext($env, $this->option('context'));
        $this->plexContext = $context;
        $kubectl = $this->secretsKubectl($context);
        $ns = $this->secretsNamespace();
        $noPlex = (bool) $this->option('no-plex');

        if (! $noPlex) {
            if (! $this->ensureCommons(['postgres', 'redis'])) {
                return 1;
            }
        }

        $host = $this->resolveSecretsHost($env, $kubectl);

        // Read or generate database password
        $dbPassword = $this->readExistingDbPassword($kubectl, $ns);
        if ($dbPassword === null) {
            $dbPassword = Str::random(24);
        }

        // Allocate database and user on Plex PostgreSQL if not faking standalone
        if (! $noPlex) {
            if (! $this->allocateDatabase(\App\Enums\DatabaseDriver::POSTGRESQL, 'infisical', $dbPassword)) {
                return 1;
            }
        }

        // Read or generate encryption-key and auth-secret
        $encryptionKey = $this->readExistingSecretKey($kubectl, $ns, 'encryption-key');
        if ($encryptionKey === null) {
            $encryptionKey = bin2hex(random_bytes(16));
        }

        $authSecret = $this->readExistingSecretKey($kubectl, $ns, 'auth-secret');
        if ($authSecret === null) {
            $authSecret = Str::random(32);
        }

        // Ensure namespace exists
        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        $vpnOnly = (bool) $this->option('vpn-only');

        if ($vpnOnly && ! $this->ensureVpnMiddleware(ClusterTool::SECRETS, $kubectl)) {
            $this->laraKubeError('Failed to create the VPN-only Middleware — check kubectl access to the cluster above and re-run.');

            return 1;
        }

        $hostAPI = 'http://infisical-backend.'.$ns.'.svc.cluster.local:8080/api';

        $manifest = view('k8s.secrets.shared', [
            'host' => $host,
            'encryptionKey' => $encryptionKey,
            'authSecret' => $authSecret,
            'dbPassword' => $dbPassword,
            'plexNamespace' => $this->plexNamespace(),
            'noPlex' => $noPlex,
            'hostAPI' => $hostAPI,
            'isLocal' => $env === 'local',
            'vpnOnly' => $vpnOnly,
        ])->render();

        $tmp = sys_get_temp_dir().'/larakube-secrets.yaml';
        file_put_contents($tmp, $manifest);

        $this->withSpin('Applying Infisical manifests...', fn () => $this->runStreaming("{$kubectl} apply -f {$tmp}"));
        @unlink($tmp);

        if ($noPlex) {
            $this->withSpin('Waiting for local database...', fn () => $this->runStreaming(
                "{$kubectl} rollout status deploy/infisical-db -n {$ns} --timeout=120s",
                130,
            ));
            $this->withSpin('Waiting for local cache...', fn () => $this->runStreaming(
                "{$kubectl} rollout status deploy/infisical-cache -n {$ns} --timeout=120s",
                130,
            ));
        }

        $this->withSpin('Waiting for Infisical Backend...', fn () => $this->runStreaming(
            "{$kubectl} rollout status deploy/infisical-backend -n {$ns} --timeout=120s",
            130,
        ));

        $this->withSpin('Waiting for Infisical Operator...', fn () => $this->runStreaming(
            "{$kubectl} rollout status deploy/infisical-operator-controller-manager -n {$ns} --timeout=120s",
            130,
        ));

        $this->registerDeployedTool(ClusterTool::SECRETS, $kubectl, $host);

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Infisical stack is live.');
        $this->newLine();
        $this->line("  <fg=gray>Access URL:</>              <fg=blue>https://{$host}</>");
        $this->line('  <fg=gray>Operator Connection:</>     <fg=blue>InfisicalConnection/infisical-connection</>');
        $this->newLine();

        if (! $this->option('no-bootstrap')) {
            $this->bootstrapInfisical($kubectl, $ns, $host);
        } else {
            $this->laraKubeInfo('Skipping auto-bootstrap (--no-bootstrap).');
            $this->line('  Complete the setup manually:');
            $this->line('  1. Log in at <fg=blue>https://'.$host.'</> and create your first account.');
            $this->line('  2. Run <fg=blue>'.$this->name.'</> without --no-bootstrap to auto-bootstrap.');
        }

        return 0;
    }

    /**
     * Auto-bootstrap the Infisical instance: create admin, project, and
     * a machine identity with Universal Auth for the operator.
     */
    protected function bootstrapInfisical(string $kubectl, string $ns, string $host): void
    {
        if ($this->isInfisicalBootstrapped($kubectl, $ns)) {
            $this->laraKubeInfo('Infisical already bootstrapped — skipping.');

            return;
        }

        $email = (string) ($this->option('admin-email') ?: $this->resolveSecretsAdminEmail());
        $password = (string) ($this->option('admin-password') ?: Str::random(24));

        $this->laraKubeNewLine();
        $this->withSpin('Bootstrapping the Infisical instance (admin, org, identity)...', function () use ($kubectl, $email, $password, &$bootstrap) {
            $bootstrap = $this->infisicalApi($kubectl, 'POST', '/api/v1/admin/bootstrap', [
                'email' => $email,
                'password' => $password,
                'organization' => 'LaraKube',
            ]);
        });

        if (! $bootstrap || ! isset($bootstrap['identity'])) {
            $this->laraKubeError('Bootstrap failed — check the Infisical logs and re-run.');
            $this->laraKubeWarn('You can complete the setup manually:');
            $this->line('  1. Log in at <fg=blue>https://'.$host.'</> and create your first account.');
            $this->line('  2. Run <fg=blue>'.$this->name.'</> again to retry bootstrapping.');

            return;
        }

        $bootstrapToken = $bootstrap['identity']['credentials']['token'];
        $orgId = $bootstrap['organization']['id'];

        $this->withSpin('Creating "LaraKube Shared Tools" project...', function () use ($kubectl, $bootstrapToken, &$project) {
            $project = $this->infisicalApi($kubectl, 'POST', '/api/v2/workspace', [
                'projectName' => 'LaraKube Shared Tools',
                'slug' => 'larakube',
                'type' => 'secret-manager',
                'shouldCreateDefaultEnvs' => false,
            ], $bootstrapToken);
        });

        if (! $project || ! isset($project['project'])) {
            $this->laraKubeError('Project creation failed.');

            return;
        }

        $projectId = $project['project']['id'];
        $projectSlug = $project['project']['slug'];

        $this->infisicalApi($kubectl, 'POST', "/api/v1/projects/{$projectId}/environments", [
            'name' => 'Production',
            'slug' => 'production',
        ], $bootstrapToken);

        $this->withSpin('Creating "larakube-operator" machine identity...', function () use ($kubectl, $orgId, $bootstrapToken, &$identity) {
            $identity = $this->infisicalApi($kubectl, 'POST', '/api/v1/identities', [
                'name' => 'larakube-operator',
                'organizationId' => $orgId,
                'role' => 'admin',
            ], $bootstrapToken);
        });

        if (! $identity || ! isset($identity['identity'])) {
            $this->laraKubeError('Identity creation failed.');

            return;
        }

        $identityId = $identity['identity']['id'];

        $this->withSpin('Attaching Universal Auth to the identity...', function () use ($kubectl, $identityId, $bootstrapToken, &$ua) {
            $ua = $this->infisicalApi($kubectl, 'POST', "/api/v1/auth/universal-auth/identities/{$identityId}", [
                'clientSecretTrustedIps' => [
                    ['ipAddress' => '0.0.0.0/0'],
                    ['ipAddress' => '::/0'],
                ],
            ], $bootstrapToken);
        });

        if (! $ua || ! isset($ua['identityUniversalAuth'])) {
            $this->laraKubeError('Universal Auth attachment failed.');

            return;
        }

        $clientId = $ua['identityUniversalAuth']['clientId'];

        $this->withSpin('Creating client secret for the identity...', function () use ($kubectl, $identityId, $bootstrapToken, &$cs) {
            $cs = $this->infisicalApi($kubectl, 'POST', "/api/v1/auth/universal-auth/identities/{$identityId}/client-secrets", [
                'description' => 'larakube-operator client secret',
            ], $bootstrapToken);
        });

        if (! $cs || ! isset($cs['clientSecret'])) {
            $this->laraKubeError('Client secret creation failed.');

            return;
        }

        $clientSecret = $cs['clientSecret'];

        $this->withSpin('Adding identity to the LaraKube project...', function () use ($kubectl, $projectId, $identityId, $bootstrapToken) {
            $this->infisicalApi($kubectl, 'POST', "/api/v1/projects/{$projectId}/memberships/identities/{$identityId}", [
                'role' => 'admin',
            ], $bootstrapToken);
        });

        // Store everything in infisical-bootstrap Secret
        $secretData = [
            'admin-email' => base64_encode($email),
            'admin-password' => base64_encode($password),
            'org-id' => base64_encode($orgId),
            'org-name' => base64_encode($bootstrap['organization']['name']),
            'org-slug' => base64_encode($bootstrap['organization']['slug']),
            'bootstrap-token' => base64_encode($bootstrapToken),
            'identity-id' => base64_encode($identityId),
            'client-id' => base64_encode($clientId),
            'client-secret' => base64_encode($clientSecret),
            'project-id' => base64_encode($projectId),
            'project-slug' => base64_encode($projectSlug),
        ];

        $yaml = "apiVersion: v1\nkind: Secret\nmetadata:\n  name: infisical-bootstrap\n  namespace: {$ns}\ntype: Opaque\ndata:\n";
        foreach ($secretData as $key => $value) {
            $yaml .= "  {$key}: {$value}\n";
        }

        $tmp = sys_get_temp_dir().'/larakube-bootstrap-secret.yaml';
        file_put_contents($tmp, $yaml);

        $this->withSpin('Storing bootstrap credentials...', fn () => Process::run("{$kubectl} apply -f {$tmp}"));
        @unlink($tmp);

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Infisical instance bootstrapped.');
        $this->newLine();
        // The admin password is auto-generated and was previously ONLY written
        // to the infisical-bootstrap Secret — never shown. The one credential
        // needed to actually log into the dashboard was the one credential the
        // operator never saw. It is printed in full here (and by secrets:show),
        // because a password you cannot read is the same as no account.
        $this->line("  <fg=gray>Dashboard:</>        <fg=blue>https://{$host}</>");
        $this->line('  <fg=gray>Admin email:</>      <fg=blue>'.$email.'</>');
        $this->line('  <fg=gray>Admin password:</>   <fg=yellow>'.$password.'</>');
        $this->newLine();
        $this->line('  <fg=gray>Project:</>          <fg=blue>LaraKube Shared Tools</> ('.$projectId.')');
        $this->line('  <fg=gray>Machine Identity:</> <fg=blue>larakube-operator</> (Universal Auth)');
        $this->line('  <fg=gray>Client ID:</>        <fg=green>'.$clientId.'</>');
        $this->line('  <fg=gray>Client Secret:</>    <fg=green>'.substr($clientSecret, 0, 12).'…</>');
        $this->newLine();
        $this->line('  <fg=gray>Lost these? They are stored on the cluster:</> <fg=blue>larakube secrets:show</>');
        $this->line('  Use the Client ID / Secret in <fg=blue>InfisicalAuth</> CRDs to sync secrets');
        $this->line('  into any namespace. Run <fg=blue>mail:init</> to wire Stalwart.');
    }

    protected function resolveSecretsAdminEmail(): string
    {
        $projectPath = getcwd();
        if (file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)) {
            $config = ConfigData::loadFromFile($projectPath);
            if ($config->hasEmail()) {
                return $config->email;
            }
        }

        $global = GlobalConfigData::load();
        if ($global->getEmail() !== null && $global->getEmail() !== '') {
            return $global->getEmail();
        }

        return 'admin@larakube.local';
    }

    /** Check if db-connection-uri points locally or to Plex Commons */
    protected function isSecretsDatabaseLocal(string $kubectl, string $ns): bool
    {
        $url = trim(Process::run(
            "{$kubectl} get secret infisical-secrets -n {$ns} -o jsonpath='{.data.db-connection-uri}'",
        )->output());

        if ($url === '') {
            return false;
        }

        return str_contains((string) base64_decode($url), 'infisical-db');
    }

    /** Parse PostgreSQL password from existing infisical-secrets Secret */
    protected function readExistingDbPassword(string $kubectl, string $ns): ?string
    {
        $url = trim(Process::run(
            "{$kubectl} get secret infisical-secrets -n {$ns} -o jsonpath='{.data.db-connection-uri}'",
        )->output());

        if ($url === '') {
            return null;
        }

        $decoded = (string) base64_decode($url);

        // Pattern: postgres://infisical:<password>@...
        if (preg_match('/^postgres:\/\/infisical:([^@]+)@/', $decoded, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /** Read existing JWT secret or encryption key */
    protected function readExistingSecretKey(string $kubectl, string $ns, string $key): ?string
    {
        $out = trim(Process::run(
            "{$kubectl} get secret infisical-secrets -n {$ns} -o jsonpath='{.data.{$key}}'",
        )->output());

        return $out !== '' ? (string) base64_decode($out) : null;
    }

    /** Resolve the Infisical ingress host for this install */
    protected function resolveSecretsHost(string $env, ?string $kubectl = null): string
    {
        return $this->resolveToolHost(SharedClusterService::SECRETS, ClusterTool::SECRETS, $env, $kubectl);
    }

    /** Decide which environment this install targets */
    protected function resolveEnvironment(): string
    {
        return $this->resolveToolEnvironment(ClusterTool::SECRETS);
    }
}
