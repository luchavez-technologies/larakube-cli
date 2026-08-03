<?php

namespace App\Commands;

use App\Enums\DatabaseDriver;
use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

class TestCommand extends Command
{
    use InteractsWithEnvironments, InteractsWithProjectConfig, LaraKubeOutput, StreamsProcessOutput;

    protected $signature = 'test
                            {--db : Provision <app>_testing on the project DB engine instead of in-memory SQLite (auto-saved to .larakube.json on first use)}
                            {--with-db : Alias for --db (kept for backward compat)}
                            {--environment=local : Cluster environment to target}
                            {--service=web : Pod label to exec into}';

    protected $description = 'Run phpunit/pest inside the web pod (defaults to in-memory SQLite; --db for engine-specific test DB)';

    /**
     * Process env vars that get stripped from the test process so phpunit.xml's
     * <env> blocks (and our own safe defaults) can take effect. K8s injects these
     * into the pod via the laravel-config ConfigMap, and they otherwise win over
     * phpunit.xml during PHP bootstrap.
     *
     * @var array<int, string>
     */
    protected array $stripVarsByDefault = [
        'DB_CONNECTION',
        'DB_DATABASE',
        'DB_HOST',
        'DB_PORT',
        'DB_USERNAME',
        'DB_PASSWORD',
        'CACHE_DRIVER',
        'CACHE_STORE',
        'SESSION_DRIVER',
        'QUEUE_CONNECTION',
        'MAIL_MAILER',
    ];

    public function __construct()
    {
        parent::__construct();

        $this->ignoreValidationErrors();
    }

    public function handle(): int
    {
        if (! $this->isLaraKubeProject()) {
            return 1;
        }

        $config = $this->getProjectConfig();
        if (! $config) {
            $this->laraKubeError('Could not load .larakube.json.');

            return 1;
        }

        $environment = $this->option('environment');
        $service = $this->option('service');
        $namespace = $this->getNamespace($environment);

        $passthroughArgs = $this->extractPassthroughArgs();

        // Decide whether to provision a real test DB or use in-memory SQLite.
        // Precedence: explicit CLI flag > blueprint default > built-in default.
        $flagPassed = $this->option('db') || $this->option('with-db');
        $driver = $config->getDatabase();
        $driverSupportsProvisioning = $driver?->getTestDatabaseProvisionCommand('x') !== null;

        // If the user asked for --db but the driver can't be provisioned
        // (SQLite: redundant — tests already use SQLite; MongoDB: not
        // implemented yet), fall back to the in-memory SQLite path. Don't
        // persist the preference — saving it would just produce the same
        // warning forever.
        if ($flagPassed && ! $driverSupportsProvisioning) {
            if ($driver === DatabaseDriver::SQLITE) {
                $this->laraKubeInfo('Note: your project uses SQLite, so --db is unnecessary. Tests already use in-memory SQLite.');
            } else {
                $this->laraKubeWarn("--db isn't supported for {$driver?->getLabel()} yet. Falling back to in-memory SQLite for tests.");
            }
            $flagPassed = false;
        }

        $withDb = $flagPassed || ($config->getProvisionTestDb() && $driverSupportsProvisioning);

        // First-time auto-persist: if user passed --db explicitly AND the
        // blueprint doesn't already have it set AND the driver supports
        // provisioning, save the preference so future `larakube test` runs
        // default to this behavior. Avoids the friction of editing
        // .larakube.json by hand.
        if ($flagPassed && ! $config->getProvisionTestDb()) {
            $config->provisionTestDb = true;
            $config->saveToFile($config->getPath());
            $this->laraKubeInfo('✓ Saved provisionTestDb=true to .larakube.json — future tests will default to the provisioned DB.');
        }

        if ($withDb) {
            if (! $this->provisionTestingDatabase($config, $namespace)) {
                return 1;
            }
            $stripVars = array_diff($this->stripVarsByDefault, ['DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD']);
            $setVars = [
                'DB_CONNECTION' => $config->getDatabase()->dbConnection(),
                'DB_DATABASE' => $config->getName().'_testing',
                'APP_ENV' => 'testing',
            ];
        } else {
            $stripVars = $this->stripVarsByDefault;
            $setVars = [
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => ':memory:',
                'CACHE_DRIVER' => 'array',
                'CACHE_STORE' => 'array',
                'SESSION_DRIVER' => 'array',
                'QUEUE_CONNECTION' => 'sync',
                'MAIL_MAILER' => 'array',
                'APP_ENV' => 'testing',
            ];
        }

        $podName = $this->findPod($service, $namespace);
        if (! $podName) {
            $this->laraKubeError("Could not find a running '{$service}' pod in '{$namespace}'.");

            return 1;
        }

        $envFragment = $this->buildEnvFragment($stripVars, $setVars);
        $artisanArgs = $passthroughArgs === '' ? '' : ' '.$passthroughArgs;

        // CRITICAL: remove the cached config file before invoking artisan test.
        // The SSU base image runs `php artisan optimize` on container start,
        // which bakes the pod's process env (DB_CONNECTION=pgsql, etc.) into
        // bootstrap/cache/config.php. Laravel reads that file BEFORE consulting
        // process env, so the env -u/set overrides above would be silently
        // ignored and RefreshDatabase would wipe the dev DB.
        $inPodCommand = sprintf(
            'rm -f /var/www/html/bootstrap/cache/config.php /var/www/html/bootstrap/cache/routes-v7.php && php artisan test%s',
            $artisanArgs,
        );

        $command = sprintf(
            'kubectl exec -it -n %s -c php %s -- env %s sh -c %s',
            escapeshellarg($namespace),
            escapeshellarg($podName),
            $envFragment,
            escapeshellarg($inPodCommand),
        );

        passthru($command, $exitCode);

        return $exitCode;
    }

    protected function provisionTestingDatabase($config, string $namespace): bool
    {
        $driver = $config->getDatabase();
        if (! $driver instanceof DatabaseDriver) {
            $this->laraKubeError('No database driver configured in .larakube.json.');

            return false;
        }

        $testDbName = $config->getName().'_testing';
        $provisionCommand = $driver->getTestDatabaseProvisionCommand($testDbName);

        if ($provisionCommand === null) {
            $this->laraKubeError(sprintf(
                "--with-db is not supported for the %s driver yet. Either drop the flag (default SQLite tests work fine) or provision '%s' manually.",
                $driver->getLabel() ?? $driver->value,
                $testDbName,
            ));

            return false;
        }

        $dbPodName = $this->findPod($driver->getPodName($config), $namespace);
        if (! $dbPodName) {
            $this->laraKubeError("Could not find the '{$driver->getPodName($config)}' DB pod in '{$namespace}'. Is the cluster up?");

            return false;
        }

        $this->laraKubeInfo("Ensuring testing database '{$testDbName}' exists on {$driver->getLabel()}...");

        $exec = sprintf(
            'kubectl exec -n %s %s -- sh -c %s',
            escapeshellarg($namespace),
            escapeshellarg($dbPodName),
            escapeshellarg($provisionCommand),
        );

        return $this->runStreaming($exec) === 0;
    }

    /**
     * Extract any args after `larakube test` that aren't our own options,
     * to pass through to `php artisan test`. Mirrors ExecCommand's pattern.
     */
    protected function extractPassthroughArgs(): string
    {
        $argv = $_SERVER['argv'] ?? [];
        $testIndex = array_search('test', $argv, true);
        if ($testIndex === false) {
            return '';
        }

        $ours = ['--db', '--with-db', '--environment', '--service'];
        $passthrough = [];

        foreach (array_slice($argv, $testIndex + 1) as $arg) {
            $isOurs = false;
            foreach ($ours as $flag) {
                if ($arg === $flag || str_starts_with($arg, $flag.'=')) {
                    $isOurs = true;
                    break;
                }
            }
            if (! $isOurs) {
                $passthrough[] = escapeshellarg($arg);
            }
        }

        return implode(' ', $passthrough);
    }

    /**
     * @param  array<int, string>  $stripVars
     * @param  array<string, string>  $setVars
     */
    protected function buildEnvFragment(array $stripVars, array $setVars): string
    {
        $parts = [];
        foreach ($stripVars as $name) {
            $parts[] = '-u '.escapeshellarg($name);
        }
        foreach ($setVars as $name => $value) {
            $parts[] = escapeshellarg($name.'='.$value);
        }

        return implode(' ', $parts);
    }

    protected function findPod(string $service, string $namespace): ?string
    {
        $labels = ["app={$service}", "app=laravel-{$service}"];

        if ($service === 'queues') {
            $labels[] = 'app=queue';
            $labels[] = 'app=laravel-queue';
        }

        foreach ($labels as $label) {
            $podName = trim(Process::run("kubectl get pods -n {$namespace} -l {$label} -o jsonpath='{.items[0].metadata.name}'")->output());
            if ($podName !== '') {
                return $podName;
            }
        }

        return null;
    }
}
