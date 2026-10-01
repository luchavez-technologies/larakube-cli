<?php

namespace App\Commands\Secrets;

use App\Data\ConfigData;
use App\Services\Kubectl;
use App\Services\PlexService;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithSecrets;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesEnvironmentContext;
use App\Traits\SyncsClusterSecrets;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

class SecretsPruneCommand extends Command
{
    use ConfirmsDestructiveAction, InteractsWithClusterContext, InteractsWithSecrets, LaraKubeOutput, ResolvesEnvironmentContext, SyncsClusterSecrets;

    /** The OpenBao database config every Commons Postgres static role is registered against. */
    private const POSTGRES_CONFIG = 'plex-postgres';

    protected $signature = 'secrets:prune
        {environment=local : Environment whose OpenBao to prune}
        {--context= : Target a specific kube-context}
        {--dry-run : List the dead static roles without deleting anything}
        {--force : Skip confirmation prompt}';

    protected $description = 'Delete OpenBao DB static roles whose Postgres role no longer exists';

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');
        $config = file_exists(getcwd().'/'.ConfigData::CONFIG_FILE) ? ConfigData::loadFromFile(getcwd()) : null;

        $context = (string) $this->option('context') ?: null;
        if (! $context && $config && $env !== 'local') {
            $context = $this->environmentContextOrCurrent($config, $env);
        }

        $kubectl = Kubectl::forContext($context)->prefix();

        if (! $this->secretsBackendAvailable($kubectl)) {
            $this->laraKubeError('OpenBao is not deployed on this cluster — nothing to prune.');

            return 1;
        }

        $token = $this->readOpenBaoBootstrapSecret($kubectl, $this->secretsNamespace(), 'root-token');
        if ($token === null) {
            $this->laraKubeError('Could not read the OpenBao root token.');

            return 1;
        }

        $roles = $this->postgresRoles($kubectl);
        if ($roles === null) {
            // Never guess: deleting a live role's rotation because the lookup failed would freeze it.
            $this->laraKubeError('Could not list the Postgres roles, so no static role can be judged dead. Nothing was changed.');

            return 1;
        }

        $static = $this->staticRoles($kubectl, $token);
        if ($static === null) {
            $this->laraKubeError('Could not list OpenBao static roles: '.($this->lastSecretsBackendError ?? 'unknown error'));

            return 1;
        }

        $dead = [];
        $skipped = [];
        foreach ($static as $name => $role) {
            if ($role === null) {
                $skipped[] = [$name, 'could not be read'];

                continue;
            }

            if ($role['db_name'] !== self::POSTGRES_CONFIG) {
                $skipped[] = [$name, "not a Commons Postgres role ({$role['db_name']})"];

                continue;
            }

            if (! in_array($role['username'], $roles, true)) {
                $dead[] = [$name, $role['username']];
            }
        }

        if ($skipped !== []) {
            $this->laraKubeInfo('Left alone:');
            table(['Static role', 'Why'], $skipped);
        }

        if ($dead === []) {
            $this->laraKubeInfo('Every static role points at a Postgres role that exists. Nothing to prune.');

            return 0;
        }

        $this->laraKubeInfo('Static roles whose Postgres role no longer exists:');
        table(['Static role', 'Postgres role (missing)'], $dead);

        if ($this->option('dry-run')) {
            $this->laraKubeInfo('Dry run — nothing was deleted.');

            return 0;
        }

        if (! $this->confirmDestructive(['Deleting '.count($dead).' OpenBao static role(s). OpenBao stops rotating them; no Postgres role or Secret is touched.'])) {
            return 0;
        }

        $failed = 0;
        foreach ($dead as [$name]) {
            if ($this->deleteStaticRole($kubectl, $name)) {
                $this->laraKubeInfo("✅ Deleted static role {$name}.");
            } else {
                $this->laraKubeError("Could not delete static role {$name}.");
                $failed++;
            }
        }

        return $failed === 0 ? 0 : 1;
    }

    /**
     * Every role in the Commons Postgres, or null when it could not be listed.
     *
     * @return list<string>|null
     */
    protected function postgresRoles(string $kubectl): ?array
    {
        $result = Process::run(
            "{$kubectl} exec -n ".PlexService::NAMESPACE.' deploy/postgres -c postgres -- psql -U postgres -tAc '.escapeshellarg('select rolname from pg_roles'),
        );

        if (! $result->successful()) {
            return null;
        }

        $names = array_values(array_filter(array_map('trim', explode("\n", $result->output()))));

        // A listing with no roles at all is a failed lookup, not an empty Postgres.
        return $names === [] ? null : $names;
    }

    /**
     * Every OpenBao static role with the Postgres role and database config it
     * manages (null for one that could not be read), or null when the list
     * itself failed.
     *
     * @return array<string, array{username: string, db_name: string}|null>|null
     */
    protected function staticRoles(string $kubectl, string $token): ?array
    {
        $list = $this->openBaoApi($kubectl, 'GET', '/v1/database/static-roles?list=true', null, $token);
        if ($list === null) {
            return null;
        }

        $out = [];
        foreach ($list['data']['keys'] ?? [] as $name) {
            $role = $this->openBaoApi($kubectl, 'GET', "/v1/database/static-roles/{$name}", null, $token);
            $username = $role['data']['username'] ?? null;
            $dbName = $role['data']['db_name'] ?? null;

            $out[$name] = is_string($username) && is_string($dbName) ? ['username' => $username, 'db_name' => $dbName] : null;
        }

        return $out;
    }
}
