<?php

namespace App\Commands\Sso;

use App\Traits\InteractsWithSsoGrants;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Str;

use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class SsoCreateCommand extends Command
{
    use InteractsWithSsoGrants, LaraKubeOutput;

    protected $signature = 'sso:create
        {environment=local : Environment whose Zitadel to target}
        {--email=    : Full email address for the new Zitadel user}
        {--name=     : Display name for the user}
        {--password= : Account password (auto-generated if omitted)}
        {--context=  : Target a specific kube-context}';

    protected $description = 'Create a new Zitadel SSO user account (baseline zero-trust account for Drive, Chat, Notes, etc.)';

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');
        $connection = $this->resolveSsoGrantConnection($env, $this->option('context'));
        if ($connection === null) {
            return 1;
        }
        [$ssoHost, $pat] = $connection;

        $email = trim((string) ($this->option('email') ?: text(
            label: 'Email address of the user',
            placeholder: 'user@example.com',
            required: true,
        )));

        if ($email === '') {
            $this->laraKubeError('An email address is required.');

            return 1;
        }

        $existingId = $this->zitadelFindUserByEmail($ssoHost, $pat, $email);
        if ($existingId !== null) {
            $this->laraKubeInfo("Zitadel SSO user account '{$email}' already exists (ID: {$existingId}).");

            return 0;
        }

        $localPart = explode('@', $email)[0];
        $displayName = (string) ($this->option('name') ?: text(
            label: 'Display name',
            placeholder: $localPart,
        ));

        $rawPassword = (string) ($this->option('password') ?: Str::password(24));

        $userId = $this->zitadelCreateUser($ssoHost, $pat, $email, $displayName, $rawPassword);
        if ($userId === null) {
            $this->laraKubeError("Failed to create Zitadel SSO user account for '{$email}'.");

            return 1;
        }

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Zitadel SSO user account created successfully.');
        $this->newLine();
        $this->line("  <fg=gray>Email:</>       <fg=blue>{$email}</>");
        $this->line("  <fg=gray>Password:</>    <fg=yellow>{$rawPassword}</>");
        $this->line("  <fg=gray>Name:</>        {$displayName}");
        $this->line("  <fg=gray>Zitadel URL:</> <fg=blue>https://{$ssoHost}</>");
        $this->newLine();
        $this->line('  <fg=gray>Zero-Trust Baseline:</> User has access to collaborative apps (Drive, Chat, Notes) as a basic member.');
        $this->line('  <fg=gray>Admin Gating:</> Attempting to log into OpenBao, Grafana, or LaraKube Console will be blocked with 403 Forbidden.');
        $this->newLine();

        return 0;
    }
}
