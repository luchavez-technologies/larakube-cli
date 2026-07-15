<?php

namespace App\Commands\Errors;

use App\Data\ConfigData;
use App\Enums\SharedClusterService;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithErrors;
use App\Traits\InteractsWithPlex;
use App\Traits\LaraKubeOutput;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class ErrorsInitCommand extends Command
{
    use InteractsWithClusterContext, InteractsWithErrors, InteractsWithPlex, LaraKubeOutput, StreamsProcessOutput;

    protected $signature = 'errors:init
        {environment? : Environment this install targets — "local" (default) or a cloud env. Omit to be prompted. A non-local env prompts for + persists the GlitchTip host.}
        {--context=  : Target a specific kube-context (defaults to current context)}
        {--env=      : Legacy alias for the environment argument}
        {--domain=   : Raw override for the GlitchTip cluster domain (e.g. example.com → errors.example.com); skips the prompt}
        {--no-plex   : Bypass Plex Commons and deploy dedicated database/cache pods instead}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--remove    : Tear down the GlitchTip stack from larakube-shared}';

    protected $description = 'Deploy the cluster-wide GlitchTip error tracking stack into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        // Scope the Plex trait context to match the passed option
        $this->plexContext = $this->option('context');

        return $this->option('remove')
            ? $this->removeErrors()
            : $this->deployErrors();
    }

    protected function deployErrors(): int
    {
        $kubectl = $this->errorsKubectl($this->option('context'));
        $ns = $this->errorsNamespace();

        $noPlex = (bool) $this->option('no-plex');

        if (! $noPlex) {
            if (! $this->ensureCommons(['postgres', 'redis'])) {
                return 1;
            }
        }

        $host = $this->resolveErrorsHost();

        // Read or generate database credentials
        $dbPassword = $this->readExistingDbPassword($kubectl, $ns);
        if ($dbPassword === null) {
            $dbPassword = Str::random(24);
        }

        // Allocate database and user on Plex PostgreSQL
        if (! $noPlex) {
            if (! $this->allocateDatabase(\App\Enums\DatabaseDriver::POSTGRESQL, 'glitchtip', $dbPassword)) {
                return 1;
            }
        }

        // Read or generate admin credentials
        $adminPassword = $this->readErrorsAdminPassword($kubectl, $ns);
        if ($adminPassword === null) {
            $adminPassword = Str::random(16);
        }

        // Ensure target namespace exists
        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        // Delete any existing migrations job first because Job specs are immutable
        Process::run("{$kubectl} delete job glitchtip-db-migrations -n {$ns} --ignore-not-found");

        $env = $this->option('env') ?: $this->argument('environment');
        if (!$env && !$this->option('domain')) {
            $env = 'local';
        }

        $vpnOnly = (bool) $this->option('vpn-only');

        $manifest = view('k8s.errors.shared', [
            'host' => $host,
            'adminPassword' => $adminPassword,
            'dbPassword' => $dbPassword,
            'plexNamespace' => $this->plexNamespace(),
            'noPlex' => $noPlex,
            'isLocal' => $env === 'local',
            'vpnOnly' => $vpnOnly,
        ])->render();

        $tmp = sys_get_temp_dir().'/larakube-errors.yaml';
        file_put_contents($tmp, $manifest);

        $this->withSpin('Applying GlitchTip manifests...', fn () => $this->runStreaming("{$kubectl} apply -f {$tmp}"));
        @unlink($tmp);

        if ($noPlex) {
            $this->withSpin('Waiting for local database...', fn () => $this->runStreaming(
                "{$kubectl} rollout status deploy/glitchtip-db -n {$ns} --timeout=120s",
                130,
            ));
            $this->withSpin('Waiting for local cache...', fn () => $this->runStreaming(
                "{$kubectl} rollout status deploy/glitchtip-cache -n {$ns} --timeout=120s",
                130,
            ));
        }

        $this->withSpin('Waiting for database migrations...', fn () => $this->runStreaming(
            "{$kubectl} wait --for=condition=complete job/glitchtip-db-migrations -n {$ns} --timeout=120s",
            130,
        ));

        $this->withSpin('Waiting for GlitchTip Web...', fn () => $this->runStreaming(
            "{$kubectl} rollout status deploy/glitchtip-web -n {$ns} --timeout=120s",
            130,
        ));

        $this->withSpin('Waiting for GlitchTip Worker...', fn () => $this->runStreaming(
            "{$kubectl} rollout status deploy/glitchtip-worker -n {$ns} --timeout=120s",
            130,
        ));

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ GlitchTip stack is live.');
        $this->newLine();
        $this->line("  <fg=gray>Access URL:</>              <fg=blue>https://{$host}</>");
        $this->line('  <fg=gray>Admin Email:</>             admin@larakube.local');
        $this->line("  <fg=gray>Admin Password:</>          {$adminPassword}");
        $this->newLine();

        return 0;
    }

    protected function removeErrors(): int
    {
        $kubectl = $this->errorsKubectl($this->option('context'));
        $ns = $this->errorsNamespace();
        $plexNs = $this->plexNamespace();

        $isStandalone = $this->isErrorsDatabaseLocal($kubectl, $ns);

        if (! $isStandalone) {
            // Dropping DB/user from Plex PostgreSQL
            $sql = $this->buildDropTenantSql('glitchtip', 'glitchtip');
            $tmp = tempnam(sys_get_temp_dir(), 'larakube_plex_drop_sql');
            file_put_contents($tmp, $sql);
            $client = \App\Enums\DatabaseDriver::POSTGRESQL->commonsAdminClient();

            $this->withSpin("Dropping database 'glitchtip' from the Commons...", function () use ($plexNs, $client, $tmp, $kubectl) {
                return Process::run(
                    "{$kubectl} exec -i -n {$plexNs} deploy/postgres -- sh -c ".escapeshellarg($client).' < '.escapeshellarg($tmp),
                )->successful();
            });
            @unlink($tmp);
        }

        // Deleting K8s resources surgically
        $this->withSpin('Removing GlitchTip resources...', fn () => Process::run(
            "{$kubectl} delete deploy/glitchtip-web deploy/glitchtip-worker deploy/glitchtip-db deploy/glitchtip-cache pvc/glitchtip-db-storage svc/glitchtip-web svc/glitchtip-db svc/glitchtip-cache ingress/glitchtip secret/glitchtip-admin job/glitchtip-db-migrations -n {$ns} --ignore-not-found",
        ));

        $this->laraKubeInfo('GlitchTip removed from larakube-shared.');

        return 0;
    }

    /** Check if database-url points locally or to Plex Commons */
    protected function isErrorsDatabaseLocal(string $kubectl, string $ns): bool
    {
        $url = trim(Process::run(
            "{$kubectl} get secret glitchtip-admin -n {$ns} -o jsonpath='{.data.database-url}'",
        )->output());

        if ($url === '') {
            return false;
        }

        return str_contains((string) base64_decode($url), 'glitchtip-db');
    }

    /**
     * Parse database user password from existing glitchtip-admin Secret.
     */
    protected function readExistingDbPassword(string $kubectl, string $ns): ?string
    {
        $url = trim(Process::run(
            "{$kubectl} get secret glitchtip-admin -n {$ns} -o jsonpath='{.data.database-url}'",
        )->output());

        if ($url === '') {
            return null;
        }

        $decoded = (string) base64_decode($url);

        // Pattern: postgres://glitchtip:<password>@...
        if (preg_match('/^postgres:\/\/glitchtip:([^@]+)@/', $decoded, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Resolve the GlitchTip ingress host for this install.
     */
    protected function resolveErrorsHost(): string
    {
        $service = SharedClusterService::ERRORS;

        $domain = (string) ($this->option('domain') ?? '');
        if ($domain !== '') {
            return $service->hostFor($domain);
        }

        $env = $this->resolveEnvironment();

        if ($env === 'local') {
            return (string) $this->resolveErrorsHostReadOnly('local', null);
        }

        return $this->promptForCloudErrorsHost($service, $env);
    }

    /**
     * Decide which environment this install targets.
     */
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
            label: 'Which environment is this GlitchTip install for?',
            options: array_combine($envs, $envs),
            default: 'local',
            hint: 'Local uses your dev TLD; a cloud env asks for + persists the GlitchTip host.',
        );
    }

    /**
     * Prompt for (and persist) a non-local GlitchTip host.
     */
    protected function promptForCloudErrorsHost(SharedClusterService $service, string $env): string
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
            placeholder: $default !== '' ? $default : 'e.g. errors.example.com',
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
