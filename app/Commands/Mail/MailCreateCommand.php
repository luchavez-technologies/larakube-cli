<?php

namespace App\Commands\Mail;

use App\Data\ConfigData;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithMail;
use App\Traits\InteractsWithStalwartApi;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Str;

use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class MailCreateCommand extends Command
{
    use InteractsWithClusterContext, InteractsWithMail, InteractsWithStalwartApi, LaraKubeOutput;

    protected $signature = 'mail:create
        {email?      : Full email address for the new account}
        {--name=     : Display name for the account}
        {--password= : Account password (auto-generated if omitted)}
        {--quota=1   : Mailbox quota in GB}
        {--context=  : Target a specific kube-context}
        {--env=      : Environment whose mail server to use (default: local)}';

    protected $description = 'Create a new Stalwart mail account';

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) ($this->option('env') ?: 'local');
        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;

        $context = (string) $this->option('context') ?: null;
        if (! $context && $config && $env !== 'local') {
            $context = $this->environmentContextOrCurrent($config, $env);
        }

        $kubectl = $this->mailKubectl($context);
        $ns = $this->mailNamespace();

        if (! $this->isMailInstalled($kubectl, $ns)) {
            $this->laraKubeError('Stalwart is not installed. Run `larakube mail:init` first.');

            return 1;
        }

        $domains = $this->stalwartDomains($kubectl, $ns);
        if ($domains === null || $domains === []) {
            $this->laraKubeError('No domains are configured in Stalwart. Add one in the admin UI first.');

            return 1;
        }

        $domainId = $domains[0]['id'] ?? null;
        $domainName = $domains[0]['name'] ?? '?';
        $domainNames = array_column($domains, 'name', 'id');

        $email = (string) ($this->argument('email') ?: text(
            label: 'Email address',
            placeholder: 'user@'.$domainName,
            required: true,
        ));

        $parts = explode('@', $email);
        $localPart = $parts[0];
        $givenDomain = $parts[1] ?? null;

        if ($givenDomain && isset(array_flip($domainNames)[$givenDomain])) {
            $domainId = array_flip($domainNames)[$givenDomain];
            $domainName = $givenDomain;
        }

        $displayName = (string) ($this->option('name') ?: text(
            label: 'Display name',
            placeholder: $localPart,
        ));

        $rawPassword = (string) ($this->option('password') ?: Str::password(24));

        // Prompt for quota in interactive mode (no positional email given), same
        // as email/name/password; a scripted call or --no-interaction uses --quota.
        $quotaGb = (! $this->argument('email') && ! $this->option('no-interaction'))
            ? (int) text(
                label: 'Mailbox quota (GB)',
                default: (string) $this->option('quota'),
                validate: fn (string $v) => is_numeric($v) && (int) $v >= 0 ? null : 'Enter a whole number of GB (0 = unlimited).',
            )
            : (int) $this->option('quota');
        $quotaBytes = $quotaGb * 1073741824;

        $created = $this->withSpin('Creating account...', function () use ($kubectl, $ns, $localPart, $domainId, $rawPassword, $displayName, $quotaBytes) {
            $responses = $this->stalwartJmap($kubectl, $ns, [[
                'x:Account/set',
                [
                    'create' => [
                        'new1' => [
                            '@type' => 'User',
                            'name' => $localPart,
                            'domainId' => $domainId,
                            'description' => $displayName,
                            // (object) cast: a plain ['0' => ...] array serializes as a
                            // JSON array via json_encode(), but Stalwart's schema requires
                            // credentials to be a JSON object keyed by credential id.
                            'credentials' => (object) [
                                '0' => [
                                    '@type' => 'Password',
                                    'secret' => $rawPassword,
                                ],
                            ],
                            'roles' => ['@type' => 'User'],
                            'permissions' => ['@type' => 'Inherit'],
                            'quotas' => ['maxDiskQuota' => $quotaBytes],
                            'encryptionAtRest' => ['@type' => 'Disabled'],
                        ],
                    ],
                ],
                'c1',
            ]]);

            return $responses;
        });

        if ($created === null) {
            $this->laraKubeError('Failed to create account. Check the Stalwart API connection.');

            return 1;
        }

        $notCreated = $created[0][1]['notCreated'] ?? null;
        if ($notCreated) {
            $error = reset($notCreated);
            $this->laraKubeError('Account creation failed: '.($error['description'] ?? 'Unknown error'));

            return 1;
        }

        $accountId = $created[0][1]['created']['new1']['id'] ?? null;

        $host = $this->resolveMailHostReadOnly($env, $config);
        $fullEmail = "{$localPart}@{$domainName}";

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Account created successfully.');
        $this->newLine();
        $this->line("  <fg=gray>Email:</>    <fg=blue>{$fullEmail}</>");
        $this->line("  <fg=gray>Password:</> <fg=yellow>{$rawPassword}</>");
        $this->line("  <fg=gray>Name:</>     {$displayName}");
        $this->line("  <fg=gray>Quota:</>    {$quotaGb} GB");

        if ($host) {
            $this->newLine();
            $this->line('  <fg=yellow>Apple Mail / Thunderbird:</>');
            $this->line("     IMAP:  <fg=blue>{$host}</>  port <fg=blue>993</>  (SSL/TLS)   ·   SMTP:  <fg=blue>{$host}</>  port <fg=blue>465</>  (SSL/TLS)");
            $this->line("     Username: <fg=blue>{$fullEmail}</>   ·   Password: the account password above");
        } else {
            $this->newLine();
            $this->line('  <fg=gray>IMAP:</> <fg=blue>993</> (SSL/TLS)   <fg=gray>SMTP:</> <fg=blue>465</> (SSL/TLS)');
        }
        $this->newLine();

        return 0;
    }
}
