<?php

namespace App\Commands\Secrets;

use App\Data\ConfigData;
use App\Enums\ClusterTool;
use App\Traits\InteractsWithAppSecretGrants;
use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class SecretsGrantCommand extends Command
{
    use InteractsWithAppSecretGrants, LaraKubeOutput;

    protected $signature = 'secrets:grant
        {environment=local : Environment whose OpenBao/Zitadel to target}
        {--app= : App name to scope access to (defaults to this project\'s name)}
        {--email= : Email of the Zitadel user to grant access to}
        {--role= : developer (read-write) or viewer (read-only)}
        {--context= : Target a specific kube-context}';

    protected $description = "Grant a user OpenBao access scoped to ONE app's ONE environment — narrower than sso:grant --tool=secrets's fixed cluster-wide tiers. See secrets:revoke, or sso:revoke for a full incident sweep.";

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

        $toolHost = $this->resolveSecretsHostReadOnly($environment, $config);
        if ($toolHost === null) {
            $this->laraKubeError("No host configured for the secrets backend in '{$environment}' — run `secrets:init` first.");

            return 1;
        }

        $role = (string) ($this->option('role') ?: '');
        if ($role === '') {
            $role = select(
                label: 'Which access level?',
                options: [
                    'developer' => 'Developer — read, create, update, patch (no delete)',
                    'viewer' => 'Viewer — read-only',
                ],
                default: 'developer',
            );
        } elseif (! in_array($role, ['developer', 'viewer'], true)) {
            $this->laraKubeError("'{$role}' isn't a valid role — use 'developer' or 'viewer'.");

            return 1;
        }

        $email = trim((string) ($this->option('email') ?: text('Email of the Zitadel user')));
        if ($email === '') {
            $this->laraKubeError('An email is required.');

            return 1;
        }

        $projectId = $this->resolveSsoProject(ClusterTool::SECRETS, $ssoHost, $pat, $kubectl);
        if ($projectId === null) {
            return 1;
        }

        $roleKey = $this->appSecretsRoleKey($app, $environment, $role);

        if (! $this->zitadelEnsureProjectRole($ssoHost, $pat, $projectId, $roleKey, "Secrets: {$app}/{$environment} ({$role})")) {
            $this->laraKubeError("Could not ensure the '{$roleKey}' role exists on ".ClusterTool::rbacProjectName().'.');

            return 1;
        }

        if (! $this->ensureAppSecretsWiring($kubectl, $app, $environment, $role, $toolHost)) {
            $this->laraKubeError('Could not wire the OpenBao policy/auth-role for this grant.');

            return 1;
        }

        $userId = $this->zitadelFindUserByEmail($ssoHost, $pat, $email);
        if ($userId === null) {
            $this->laraKubeError("No Zitadel user found for '{$email}'.");

            return 1;
        }

        if (! $this->zitadelGrantRole($ssoHost, $pat, $userId, $projectId, $roleKey)) {
            $this->laraKubeError("Failed to grant '{$roleKey}' to {$email}.");

            return 1;
        }

        $this->laraKubeInfo("✅ Granted {$email} '{$role}' access to '{$app}' secrets in '{$environment}'.");
        $this->line("  <fg=gray>OpenBao role key:</> <fg=blue>{$roleKey}</>");
        $this->line('  <fg=gray>Scope:</> <fg=blue>secret/data/'.$environment.'/'.$app.'/*</>'.($role === 'developer' ? ' <fg=gray>(read-write)</>' : ' <fg=gray>(read-only)</>'));
        $this->newLine();

        return 0;
    }
}
