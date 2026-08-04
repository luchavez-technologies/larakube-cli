<?php

namespace App\Commands\Secrets;

use App\Data\ConfigData;
use App\Enums\ClusterTool;
use App\Traits\InteractsWithAppSecretGrants;
use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class SecretsRevokeCommand extends Command
{
    use InteractsWithAppSecretGrants, LaraKubeOutput;

    protected $signature = 'secrets:revoke
        {environment=local : Environment whose OpenBao/Zitadel to target}
        {--app= : App name to revoke access to (defaults to this project\'s name)}
        {--email= : Email of the Zitadel user to revoke access from}
        {--role= : developer or viewer — skips the picker when this user only holds one}
        {--context= : Target a specific kube-context}
        {--force : Skip the confirmation prompt}';

    protected $description = "Revoke a user's app-scoped secrets access granted by secrets:grant. For a compromised account, use sso:revoke instead — its discovery sweep finds these grants too.";

    public function handle(): int
    {
        $this->renderHeader();

        $connection = $this->resolveSsoGrantConnection((string) $this->argument('environment'), $this->option('context'));
        if ($connection === null) {
            return 1;
        }
        [$ssoHost, $pat, $kubectl] = $connection;

        $environment = (string) $this->argument('environment');
        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE) ? ConfigData::loadFromFile($projectPath) : null;
        $app = $this->resolveGrantApp($config);

        $email = trim((string) ($this->option('email') ?: text('Email of the Zitadel user')));
        if ($email === '') {
            $this->laraKubeError('An email is required.');

            return 1;
        }

        $userId = $this->zitadelFindUserByEmail($ssoHost, $pat, $email);
        if ($userId === null) {
            $this->laraKubeError("No Zitadel user found for '{$email}'.");

            return 1;
        }

        $projectId = $this->resolveSsoProject(ClusterTool::SECRETS, $ssoHost, $pat, $kubectl);
        if ($projectId === null) {
            return 1;
        }

        $roleKeys = $this->resolveRolesToRevoke($ssoHost, $pat, $userId, $projectId, $app, $environment, $email);
        if ($roleKeys === null) {
            return 1;
        }
        if ($roleKeys === []) {
            return 0;
        }

        if (! $this->option('force') && ! confirm('Revoke ['.implode(', ', $roleKeys)."] from {$email}?", false)) {
            $this->laraKubeInfo('Cancelled.');

            return 0;
        }

        foreach ($roleKeys as $roleKey) {
            if (! $this->zitadelRevokeRole($ssoHost, $pat, $userId, $projectId, $roleKey)) {
                $this->laraKubeError("Failed to revoke '{$roleKey}' from {$email}.");

                return 1;
            }
        }

        $this->laraKubeInfo('✅ Revoked ['.implode(', ', $roleKeys)."] from {$email}.");
        $this->newLine();

        return 0;
    }

    /**
     * Scoped to THIS (app, environment) only — deliberately narrower than
     * sso:revoke's full-account sweep, which is the right tool when the
     * account itself (not just one grant) is the concern.
     *
     * @return array<int, string>|null null on a resolution failure, [] when
     *                                 there's nothing to revoke
     */
    protected function resolveRolesToRevoke(string $ssoHost, string $pat, string $userId, string $projectId, string $app, string $environment, string $email): ?array
    {
        $explicitRole = (string) ($this->option('role') ?: '');
        if ($explicitRole !== '') {
            if (! in_array($explicitRole, ['developer', 'viewer'], true)) {
                $this->laraKubeError("'{$explicitRole}' isn't a valid role — use 'developer' or 'viewer'.");

                return null;
            }

            return [$this->appSecretsRoleKey($app, $environment, $explicitRole)];
        }

        $grant = $this->zitadelFindUserGrant($ssoHost, $pat, $userId, $projectId);
        $prefix = "secrets-{$app}-{$environment}-";
        $held = array_values(array_filter($grant['roleKeys'] ?? [], fn (string $key) => str_starts_with($key, $prefix)));

        if ($held === []) {
            $this->laraKubeInfo("{$email} holds no '{$app}'/{$environment} secrets access — nothing to revoke.");

            return [];
        }

        if (count($held) === 1) {
            return $held;
        }

        return multiselect(
            label: "{$email}'s '{$app}'/{$environment} access — select what to revoke",
            options: array_combine($held, $held),
            default: [],
        );
    }
}
