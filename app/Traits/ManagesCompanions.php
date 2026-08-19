<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\CacheDriver;
use App\Enums\CompanionDriver;
use App\Enums\DatabaseDriver;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\select;
use function Laravel\Prompts\table;

use Spatie\TemporaryDirectory\TemporaryDirectory;

trait ManagesCompanions
{
    use InteractsWithHosts, ReadsEnvSources;

    protected function deployCompanion(CompanionDriver $companion): void
    {
        // Resolve the dev TLD fresh from disk rather than leaning on the shared
        // `$localTld` view var — that's set once at provider boot, so when
        // `config:tld` mutates the global TLD and chains `larakube up` in the SAME
        // process, the shared value is still the old TLD and the companion ingress
        // host (companion.{tld}) is stranded on it (companion.kube 200,
        // companion.test 404). Passing it explicitly overrides the stale share, so
        // a config:tld → up re-points the ingress the same way Mailpit's does.
        $manifest = view('k8s.companion.global', [
            'companion' => $companion,
            'localTld' => GlobalConfigData::load()->getLocalTld(),
        ])->render();
        $temporaryDirectory = TemporaryDirectory::make();
        $tmp = $temporaryDirectory->path('larakube-companion-'.$companion->value.'.yaml');
        file_put_contents($tmp, $manifest);
        Process::run('kubectl apply -f '.escapeshellarg($tmp));
        $temporaryDirectory->delete();
    }

    protected function removeCompanion(CompanionDriver $companion): void
    {
        foreach (['deployment', 'service', 'ingress'] as $kind) {
            Process::run("kubectl delete {$kind} ".escapeshellarg($companion->value).' -n larakube-companions --ignore-not-found=true');
        }
    }

    /**
     * Scale a companion's Deployment in larakube-companions. 0 pauses it (the
     * Deployment, Service, Ingress, and any config like phpMyAdmin's PMA_HOSTS
     * are preserved — the companion analogue of `larakube stop`); 1 resumes it.
     * Note: a subsequent `larakube up` re-applies needed companions and will
     * scale a paused one back up, since `up` re-asserts desired state.
     */
    protected function scaleCompanion(CompanionDriver $companion, int $replicas): void
    {
        Process::run('kubectl scale deployment '.escapeshellarg($companion->value)." --replicas={$replicas} -n larakube-companions");
    }

    /**
     * Let the user pick from the companions currently deployed in
     * larakube-companions. Returns null (with a friendly note) when none are
     * installed, so callers can exit cleanly. $action is the verb shown in the
     * prompt (e.g. "stop", "remove").
     */
    protected function selectInstalledCompanion(string $action): ?CompanionDriver
    {
        $installed = array_filter(CompanionDriver::cases(), fn ($c) => $this->isCompanionInstalled($c));

        if ($installed === []) {
            $this->line('  <fg=gray>No companions are installed.</>');

            return null;
        }

        $choices = [];
        foreach ($installed as $companion) {
            $choices[$companion->value] = $companion->getLabel();
        }

        return CompanionDriver::from(select("Which companion would you like to {$action}?", $choices));
    }

    protected function isCompanionInstalled(CompanionDriver $companion): bool
    {
        $result = Process::run('kubectl get deployment '.escapeshellarg($companion->value).' -n larakube-companions --no-headers')->output();

        return trim($result) !== '';
    }

    protected function ensureCompanionNamespace(): void
    {
        Process::run('kubectl create namespace larakube-companions --dry-run=client -o yaml | kubectl apply -f -');
    }

    /**
     * Print this project's companion connection details at the end of `larakube up`
     * (and re-viewable via `companion:list`). Renders as a Laravel Prompts table so
     * the host/credentials line up in aligned columns instead of long wrapping lines.
     * Only runs for local; skips silently when no relevant companion is installed.
     */
    protected function showCompanionAccess(ConfigData $config, string $appName, string $environment): void
    {
        $rows = $this->companionAccessRows($config, $appName, $environment);

        if ($rows === []) {
            return;
        }

        $this->newLine();
        $this->line("  <fg=yellow;options=bold>🔌 Companions — {$appName}</>");
        table(['Companion', 'URL', 'Host', 'User', 'Password', 'Database'], $rows);
    }

    /**
     * Build the connection rows (companion, URL, host, user, password, database) for
     * this project's installed companions. Host/credentials reflect what the local app
     * pod actually consumes, so they're correct for self-hosted databases AND Plex
     * tenants (whose Commons creds live in .env, not the enum defaults). The per-driver
     * rows are gated on withCompanions — a project that opted out doesn't want LaraKube
     * managing/showing its data tooling. Mailpit is unconditional cluster-wide infra.
     * Split out from rendering so it's unit-testable without invoking Prompts table().
     *
     * @return array<int, array<int, string>>
     */
    protected function companionAccessRows(ConfigData $config, string $appName, string $environment): array
    {
        if ($environment !== 'local') {
            return [];
        }

        $tld = GlobalConfigData::load()->getLocalTld();

        // Mailpit is always running in larakube-shared — show it unconditionally.
        $rows = [
            ['Mailpit', "<fg=blue>https://mailpit.{$tld}</>", '—', '—', '—', '—'],
        ];

        if (! $config->withCompanions) {
            return $rows;
        }

        $db = $config->getDatabase();
        $cache = $config->getCacheDriver();

        // Resolve the connection the local app pod actually consumes, so host and
        // credentials are correct for self-hosted databases AND Plex tenants.
        $conn = $this->resolveConnectionEnv($config->getNamespace($environment), $config->getPath());

        // Fall back to the self-hosted defaults only when a value can't be resolved
        // (e.g. cluster unreachable and .env carries no DB_* yet).
        $dbHost = $conn['DB_HOST'] ?? ($db instanceof DatabaseDriver ? "{$db->getPodName()}.{$appName}.svc.cluster.local" : null);
        $dbUser = $conn['DB_USERNAME'] ?? $db?->dbUsername();
        $dbPass = $conn['DB_PASSWORD'] ?? null;
        $dbName = $conn['DB_DATABASE'] ?? 'laravel';

        if ($this->isCompanionInstalled(CompanionDriver::ADMINER)
            && $db instanceof DatabaseDriver
            && $db !== DatabaseDriver::SQLITE
        ) {
            $rows[] = ['Adminer', "<fg=blue>https://adminer.{$tld}</>", $this->dashOr($dbHost), $this->dashOr($dbUser), $this->dashOr($dbPass), $this->dashOr($dbName)];
        }

        if ($this->isCompanionInstalled(CompanionDriver::PHPMYADMIN)
            && $db instanceof DatabaseDriver
            && in_array($db, [DatabaseDriver::MYSQL, DatabaseDriver::MARIADB], true)
        ) {
            $rows[] = ['phpMyAdmin', "<fg=blue>https://phpmyadmin.{$tld}</>", $this->dashOr($dbHost), $this->dashOr($dbUser), $this->dashOr($dbPass), $this->dashOr($dbName)];
        }

        if ($this->isCompanionInstalled(CompanionDriver::PGADMIN)
            && $db === DatabaseDriver::POSTGRESQL
        ) {
            $port = $conn['DB_PORT'] ?? '5432';
            $host = $dbHost !== null ? "{$dbHost}:{$port}" : '—';
            $rows[] = ['pgAdmin', "<fg=blue>https://pgadmin.{$tld}</>", $host, $this->dashOr($dbUser), $this->dashOr($dbPass), $this->dashOr($dbName)];
        }

        if ($this->isCompanionInstalled(CompanionDriver::REDISINSIGHT)
            && $cache === CacheDriver::REDIS
        ) {
            $redisHost = $conn['REDIS_HOST'] ?? "redis.{$appName}.svc.cluster.local";
            $redisPort = $conn['REDIS_PORT'] ?? '6379';
            $redisPass = $conn['REDIS_PASSWORD'] ?? null;
            $pass = ($redisPass === 'null') ? null : $redisPass;
            $rows[] = ['RedisInsight', "<fg=blue>https://redisinsight.{$tld}</>", "{$redisHost}:{$redisPort}", '—', $this->dashOr($pass), '—'];
        }

        if ($this->isCompanionInstalled(CompanionDriver::MONGO_EXPRESS)
            && $db === DatabaseDriver::MONGODB
        ) {
            $mongoHost = $dbHost ?? "mongodb.{$appName}.svc.cluster.local";
            $rows[] = ['Mongo Express', "<fg=blue>https://mongo-express.{$tld}</>", "{$mongoHost}:27017", $this->dashOr($dbUser), $this->dashOr($dbPass), $this->dashOr($dbName)];
        }

        return $rows;
    }

    /** Table-cell value, or a dim em-dash when null/empty. */
    protected function dashOr(?string $value): string
    {
        return ($value === null || $value === '') ? '—' : $value;
    }

    /**
     * Resolve the service-connection env the local app pod actually consumes:
     * the live `laravel-config` ConfigMap + `laravel-secrets` Secret (which carry
     * self-hosted DB_ and REDIS_ vars via envFrom) overlaid on the project's
     * mounted .env — where a Plex tenant's Commons credentials live, since those are
     * deliberately kept out of the ConfigMap/Secret. envFrom wins over .env in the
     * pod, so the cluster values are merged last. Read-only; degrades to whatever
     * is available when the cluster is unreachable.
     *
     * @return array<string, string>
     */
    protected function resolveConnectionEnv(string $namespace, string $projectPath): array
    {
        $env = [];

        // Lowest precedence: the .env the hostPath-mounted pod reads.
        $envPath = $projectPath.'/.env';
        if (is_file($envPath)) {
            $env = $this->parseDotenvVars((string) file_get_contents($envPath));
        }

        // Highest precedence: what the pod receives via envFrom (self-hosted services).
        return array_merge(
            $env,
            $this->readClusterEnvVars('configmap', 'laravel-config', $namespace, false),
            $this->readClusterEnvVars('secret', 'laravel-secrets', $namespace, true),
        );
    }

    /**
     * Re-point (never auto-install) whichever of this project's database/cache
     * companions is already running, so an already-installed companion's ingress
     * doesn't get stranded on an old TLD after `config:tld`. Companions are
     * shared, cluster-wide, opt-in tooling — they're only ever created via
     * `larakube companion:add` (or `up --companions` on a project that already
     * has one), never silently spun up just because a project's DB/cache happens
     * to match. Only runs when withCompanions is enabled — a project that opts
     * out doesn't want LaraKube touching its data tooling at all.
     *
     * Each driver maps to its single best-fit companion rather than spinning up
     * every tool that could theoretically work — running three different admin
     * UIs for one MySQL database defeats the point of sharing companions
     * cluster-wide to begin with.
     *
     * Full persistent auto-registration (no manual server entry needed) is only
     * implemented for phpMyAdmin today — it's the only one of these tools verified
     * to support a hot-reloadable multi-server list (PMA_HOSTS), via
     * refreshPhpMyAdminServers() below (which has its own isCompanionInstalled
     * guard, so it no-ops the same way when phpMyAdmin was never added).
     */
    protected function ensureProjectCompanions(ConfigData $config, string $appName): void
    {
        if (! $config->withCompanions) {
            return;
        }

        $db = $config->getDatabase();
        $cache = $config->getCacheDriver();

        $dbCompanion = match (true) {
            in_array($db, [DatabaseDriver::MYSQL, DatabaseDriver::MARIADB], true) => CompanionDriver::PHPMYADMIN,
            $db === DatabaseDriver::POSTGRESQL => CompanionDriver::PGADMIN,
            $db === DatabaseDriver::MONGODB => CompanionDriver::MONGO_EXPRESS,
            default => null,
        };

        $needed = array_filter([
            $dbCompanion,
            $cache === CacheDriver::REDIS ? CompanionDriver::REDISINSIGHT : null,
        ]);

        // Re-apply (kubectl apply is idempotent) ONLY companions that are already
        // installed — otherwise a companion installed under a previous TLD keeps
        // its stale ingress host (e.g. phpmyadmin.kube) after `config:tld`, so its
        // advertised URL (phpmyadmin.localhost) 404s. Re-applying re-renders the
        // ingress on the current TLD; an unchanged manifest is a no-op. Skipping
        // companions that aren't installed is what keeps `up` from silently
        // installing one a project never asked for (mirrors the presenceProbe
        // gating SharedClusterService uses for Grafana et al.).
        foreach ($needed as $companion) {
            if (! $this->isCompanionInstalled($companion)) {
                continue;
            }

            $this->ensureCompanionNamespace();
            $this->deployCompanion($companion);
        }

        $this->refreshPhpMyAdminServers($config, $appName);
    }

    /**
     * Sync installed companion hosts (pgAdmin, RedisInsight, phpMyAdmin, etc.) to
     * /etc/hosts and the Windows hosts file on WSL.
     *
     * Called after ensureProjectCompanions() in UpCommand — companions are deployed
     * before their hosts are synced. Without this, companion ingress hosts existed
     * only in the cluster and were invisible to the browser on WSL/Windows.
     * Automated (no confirm prompt); skips if companions are disabled.
     */
    protected function syncCompanionHosts(ConfigData $config): void
    {
        if (! $config->withCompanions) {
            return;
        }

        $tld = GlobalConfigData::load()->getLocalTld();
        $hosts = [];

        $db = $config->getDatabase();
        $cache = $config->getCacheDriver();

        if ($this->isCompanionInstalled(CompanionDriver::PGADMIN) && $db === DatabaseDriver::POSTGRESQL) {
            $hosts[] = "pgadmin.{$tld}";
        }
        if ($this->isCompanionInstalled(CompanionDriver::REDISINSIGHT) && $cache === CacheDriver::REDIS) {
            $hosts[] = "redisinsight.{$tld}";
        }
        if ($this->isCompanionInstalled(CompanionDriver::PHPMYADMIN)
            && $db instanceof DatabaseDriver
            && in_array($db, [DatabaseDriver::MYSQL, DatabaseDriver::MARIADB], true)
        ) {
            $hosts[] = "phpmyadmin.{$tld}";
        }
        if ($this->isCompanionInstalled(CompanionDriver::ADMINER)
            && $db instanceof DatabaseDriver
            && $db !== DatabaseDriver::SQLITE
        ) {
            $hosts[] = "adminer.{$tld}";
        }
        if ($this->isCompanionInstalled(CompanionDriver::MONGO_EXPRESS) && $db === DatabaseDriver::MONGODB) {
            $hosts[] = "mongo-express.{$tld}";
        }

        if ($hosts === []) {
            return;
        }

        $appName = $config->getName();

        if ($this->isWsl()) {
            $this->syncWindowsHosts($hosts, "{$appName}-companions");
        }

        $this->syncHostsEntries($hosts, "{$appName}-companions");
    }

    /**
     * Keep phpMyAdmin aware of this project's MySQL/MariaDB server.
     *
     * Stores the known FQDN list in ConfigMap `phpmyadmin-hosts` in
     * larakube-companions, patches the Deployment's PMA_HOSTS env var to
     * match, then triggers a rolling restart so the change takes effect.
     */
    protected function refreshPhpMyAdminServers(ConfigData $config, string $appName): void
    {
        if (! $this->isCompanionInstalled(CompanionDriver::PHPMYADMIN)) {
            return;
        }

        $db = $config->getDatabase();

        if (! $db instanceof DatabaseDriver || ! in_array($db, [DatabaseDriver::MYSQL, DatabaseDriver::MARIADB], true)) {
            return;
        }

        $fqdn = "{$db->getPodName()}.{$appName}.svc.cluster.local";

        $existing = trim(Process::run("kubectl get configmap phpmyadmin-hosts -n larakube-companions -o jsonpath='{.data.hosts}'")->output());

        $hosts = $existing !== '' ? explode(',', $existing) : [];
        $hosts = array_values(array_unique(array_filter(array_map('trim', $hosts))));

        if (! in_array($fqdn, $hosts, true)) {
            $hosts[] = $fqdn;
        }

        $hostsStr = implode(',', $hosts);

        $yaml = implode("\n", [
            'apiVersion: v1',
            'kind: ConfigMap',
            'metadata:',
            '  name: phpmyadmin-hosts',
            '  namespace: larakube-companions',
            'data:',
            "  hosts: \"{$hostsStr}\"",
        ]);

        $temporaryDirectory = TemporaryDirectory::make();
        $tmp = $temporaryDirectory->path('larakube-pma-hosts.yaml');
        file_put_contents($tmp, $yaml);
        Process::run('kubectl apply -f '.escapeshellarg($tmp));
        $temporaryDirectory->delete();

        Process::run('kubectl set env deployment/phpmyadmin PMA_HOSTS='.escapeshellarg($hostsStr).' -n larakube-companions');
        Process::run('kubectl rollout restart deployment/phpmyadmin -n larakube-companions');
    }
}
