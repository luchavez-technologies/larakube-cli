<?php

namespace App\Commands\Secrets;

use App\Data\ConfigData;
use App\Enums\SharedClusterService;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithSecrets;
use App\Traits\LaraKubeOutput;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class SecretsInitCommand extends Command
{
    use InteractsWithClusterContext, InteractsWithPlex, InteractsWithSecrets, LaraKubeOutput, StreamsProcessOutput;

    protected $signature = 'secrets:init
        {environment? : Environment this install targets — "local" (default) or a cloud env. Omit to be prompted. A non-local env prompts for + persists the Infisical host.}
        {--context=  : Target a specific kube-context (defaults to current context)}
        {--env=      : Legacy alias for the environment argument}
        {--domain=   : Raw override for the Infisical cluster domain (e.g. example.com → secrets.example.com); skips the prompt}
        {--no-plex   : Bypass Plex Commons and deploy dedicated database/cache pods instead}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--remove    : Tear down the Infisical stack and namespace}';

    protected $description = 'Deploy the cluster-wide Infisical secrets management stack';

    public function handle(): int
    {
        $this->renderHeader();

        // Scope the Plex trait context to match the passed option
        $this->plexContext = $this->option('context');

        return $this->option('remove')
            ? $this->removeSecrets()
            : $this->deploySecrets();
    }

    protected function deploySecrets(): int
    {
        $kubectl = $this->secretsKubectl($this->option('context'));
        $ns = $this->secretsNamespace();
        $noPlex = (bool) $this->option('no-plex');

        if (! $noPlex) {
            if (! $this->ensureCommons(['postgres', 'redis'])) {
                return 1;
            }
        }

        $host = $this->resolveSecretsHost();

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
            $encryptionKey = bin2hex(random_bytes(32)); // exactly 32-bytes hex
        }

        $authSecret = $this->readExistingSecretKey($kubectl, $ns, 'auth-secret');
        if ($authSecret === null) {
            $authSecret = Str::random(32);
        }

        // Ensure namespace exists
        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        $env = $this->option('env') ?: $this->argument('environment');
        if (!$env && !$this->option('domain')) {
            $env = 'local';
        }

        $vpnOnly = (bool) $this->option('vpn-only');

        $manifest = view('k8s.secrets.shared', [
            'host' => $host,
            'encryptionKey' => $encryptionKey,
            'authSecret' => $authSecret,
            'dbPassword' => $dbPassword,
            'plexNamespace' => $this->plexNamespace(),
            'noPlex' => $noPlex,
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

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Infisical stack is live.');
        $this->newLine();
        $this->line("  <fg=gray>Access URL:</>              <fg=blue>https://{$host}</>");
        $this->newLine();

        return 0;
    }

    protected function removeSecrets(): int
    {
        $kubectl = $this->secretsKubectl($this->option('context'));
        $ns = $this->secretsNamespace();
        $plexNs = $this->plexNamespace();

        // Dropping DB/user from Plex PostgreSQL if it was allocated
        $dbPassword = $this->readExistingDbPassword($kubectl, $ns);
        $isStandalone = $dbPassword !== null && $this->isSecretsDatabaseLocal($kubectl, $ns);

        if (! $isStandalone) {
            $sql = $this->buildDropTenantSql('infisical', 'infisical');
            $tmp = tempnam(sys_get_temp_dir(), 'larakube_plex_drop_sql');
            file_put_contents($tmp, $sql);
            $client = \App\Enums\DatabaseDriver::POSTGRESQL->commonsAdminClient();

            $this->withSpin("Dropping database 'infisical' from the Commons...", function () use ($plexNs, $client, $tmp, $kubectl) {
                return Process::run(
                    "{$kubectl} exec -i -n {$plexNs} deploy/postgres -- sh -c ".escapeshellarg($client).' < '.escapeshellarg($tmp),
                )->successful();
            });
            @unlink($tmp);
        }

        // Deleting entire namespace
        $this->withSpin('Removing Infisical namespace...', fn () => Process::run(
            "{$kubectl} delete namespace {$ns} --ignore-not-found",
        ));

        $this->laraKubeInfo('Infisical stack removed.');

        return 0;
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
    protected function resolveSecretsHost(): string
    {
        $service = SharedClusterService::SECRETS;

        $domain = (string) ($this->option('domain') ?? '');
        if ($domain !== '') {
            return $service->hostFor($domain);
        }

        $env = $this->resolveEnvironment();

        if ($env === 'local') {
            return (string) $this->resolveSecretsHostReadOnly('local', null);
        }

        return $this->promptForCloudSecretsHost($service, $env);
    }

    /** Decide which environment this install targets */
    protected function resolveEnvironment(): string
    {
        $explicit = (string) ($this->argument('environment') ?: $this->option('env') ?: '');
        if ($explicit !== '') {
            return $explicit;
        }

        if ($this->option('no-interaction') || $this->option('domain')) {
            return 'local';
        }

        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;

        $envs = $config ? array_merge(['local'], $config->getCloudEnvironments()) : ['local'];

        return select(
            label: 'Which environment is this Infisical install for?',
            options: array_combine($envs, $envs),
            default: 'local',
            hint: 'Local uses your dev TLD; a cloud env asks for + persists the Infisical host.',
        );
    }

    /** Prompt for (and persist) a non-local Infisical host */
    protected function promptForCloudSecretsHost(SharedClusterService $service, string $env): string
    {
        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;

        $existing = $config?->getEnvironment($env)?->hosts[$service->value] ?? null;
        if ($existing) {
            return $existing;
        }

        $webHost = $config?->getEnvironment($env)?->hosts['web'] ?? null;
        $default = ($config && $webHost) ? $config->getSharedServiceHost($service, $env) : '';

        $host = text(
            label: "What host should {$service->label()} use in '{$env}'?",
            placeholder: $default !== '' ? $default : 'e.g. secrets.example.com',
            default: $default,
            required: true,
            hint: 'Point this DNS at the cluster and add TLS like any other ingress host.',
        );

        if ($config) {
            $config->setHost($env, $service->value, $host);
            $config->saveToFile($projectPath);
            $this->laraKubeInfo("Saved {$service->label()} host for '{$env}' to .larakube.json");
        }

        return $host;
    }
}
