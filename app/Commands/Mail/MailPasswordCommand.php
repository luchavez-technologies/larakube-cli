<?php

namespace App\Commands\Mail;

use App\Data\ConfigData;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithMail;
use App\Traits\InteractsWithStalwartApi;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Str;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;

class MailPasswordCommand extends Command
{
    use InteractsWithClusterContext, InteractsWithMail, InteractsWithStalwartApi, LaraKubeOutput;

    protected $signature = 'mail:password
        {email?      : Email address of the account}
        {--password= : New password (auto-generated if omitted)}
        {--force     : Skip confirmation prompt}
        {--context=  : Target a specific kube-context}
        {--env=      : Environment whose mail server to use (default: local)}';

    protected $description = 'Reset the password for a Stalwart mail account';

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

        $accounts = $this->stalwartAccounts($kubectl, $ns);
        if ($accounts === null) {
            $this->laraKubeError('Could not connect to the Stalwart API.');

            return 1;
        }

        if ($accounts === []) {
            $this->laraKubeInfo('No accounts found.');

            return 0;
        }

        $target = $this->resolveTarget($accounts);

        if ($target === null) {
            return 1;
        }

        if (! $this->option('force')) {
            $this->laraKubeNewLine();
            $this->line("  <fg=yellow>⚠ This immediately invalidates {$target['email']}'s current password.</>");
            $this->line('  Any device or client still signed in with the old one — Apple Mail, Thunderbird,');
            $this->line('  IMAP/SMTP, or a tool wired via `mail:wire` — stops authenticating until you');
            $this->line('  update it with the new password.');
            $this->newLine();

            $confirmed = confirm(
                label: "Reset the password for '{$target['email']}'?",
                default: false,
            );

            if (! $confirmed) {
                $this->laraKubeInfo('Password reset cancelled.');

                return 0;
            }
        }

        $newPassword = (string) ($this->option('password') ?: Str::password(24));

        $updated = false;
        $this->withSpin('Updating password...', function () use (&$updated, $kubectl, $ns, $target, $newPassword) {
            $responses = $this->stalwartJmap($kubectl, $ns, [[
                'x:Account/set',
                [
                    'update' => [
                        $target['id'] => [
                            // (object) cast: a plain ['0' => ...] array serializes as a
                            // JSON array via json_encode(), but Stalwart's schema requires
                            // credentials to be a JSON object keyed by credential id.
                            'credentials' => (object) [
                                '0' => [
                                    '@type' => 'Password',
                                    'secret' => $newPassword,
                                ],
                            ],
                        ],
                    ],
                ],
                'c1',
            ]]);

            $updated = array_key_exists($target['id'], $responses[0][1]['updated'] ?? []);
        });

        if (! $updated) {
            $this->laraKubeError('Failed to update the password. Check the Stalwart API connection.');

            return 1;
        }

        $this->laraKubeNewLine();
        $this->laraKubeInfo("✅ Password updated for {$target['email']}.");
        $this->line("  <fg=gray>New password:</> <fg=yellow>{$newPassword}</>");
        $this->newLine();

        return 0;
    }

    protected function resolveTarget(array $accounts): ?array
    {
        $email = (string) ($this->argument('email') ?? '');

        if ($email !== '') {
            foreach ($accounts as $a) {
                $addr = $a['emailAddress'] ?? ($a['name'].'@?');
                if ($addr === $email) {
                    return ['id' => $a['id'], 'email' => $addr, 'name' => $a['description'] ?? $a['name']];
                }
            }

            $this->laraKubeError("Account '{$email}' not found.");

            return null;
        }

        $options = [];
        foreach ($accounts as $a) {
            $addr = $a['emailAddress'] ?? ($a['name'].'@?');
            $options[$a['id']] = $addr.' — '.($a['description'] ?? $a['name']);
        }

        $choice = select(
            label: 'Which account would you like to update?',
            options: $options,
            scroll: count($options),
        );

        foreach ($accounts as $a) {
            if ($a['id'] === $choice) {
                return ['id' => $a['id'], 'email' => $a['emailAddress'] ?? ($a['name'].'@?'), 'name' => $a['description'] ?? $a['name']];
            }
        }

        return null;
    }
}
