<?php

namespace App\Commands\Mail;

use App\Data\ConfigData;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithMail;
use App\Traits\InteractsWithStalwartApi;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class MailShowCommand extends Command
{
    use InteractsWithClusterContext, InteractsWithMail, InteractsWithStalwartApi, LaraKubeOutput;

    protected $signature = 'mail:show
        {email?     : Show client setup for this account instead of admin access (never shows its password — that\'s never recoverable; use mail:password to reset it)}
        {--context= : Target a specific kube-context}
        {--env=      : Environment whose mail server to show (default: local)}';

    protected $description = 'Show Stalwart admin credentials and access info, or a specific account\'s client setup';

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

        $email = (string) ($this->argument('email') ?? '');
        if ($email !== '') {
            return $this->showAccount($kubectl, $ns, $env, $config, $email);
        }

        $host = $this->resolveMailHostReadOnly($env, $config);
        $adminPassword = $this->readMailSecret($kubectl, $ns, 'admin-password');

        if ($adminPassword === null) {
            $this->laraKubeError('Could not read admin password from secrets.');

            return 1;
        }

        $this->newLine();
        $this->line(' <fg=blue>Stalwart Mail Server</>');
        $this->line(' '.str_repeat('─', 40));
        $this->newLine();

        if ($host) {
            $this->line("  <fg=gray>Admin URL:</>      <fg=blue>https://{$host}/admin</>");
        }
        $this->line('  <fg=gray>Admin login:</>    <fg=blue>admin</>');
        $this->line("  <fg=gray>Admin password:</> <fg=yellow>{$adminPassword}</>");
        $this->newLine();

        if ($host) {
            $this->line('  <fg=gray>IMAP:</>          <fg=blue>'.$host.'</>  port <fg=blue>993</> (SSL/TLS)');
            $this->line('  <fg=gray>SMTP:</>          <fg=blue>'.$host.'</>  port <fg=blue>465</> (SSL/TLS)');
            $this->line('  <fg=gray>SMTP (alt):</>    <fg=blue>'.$host.'</>  port <fg=blue>587</> (STARTTLS — only if you added a 587 listener)');
            $this->newLine();
        }

        $this->line('  Share these credentials with your teammates so they can');
        $this->line('  configure their email clients (Apple Mail, Thunderbird, etc.).');
        $this->newLine();

        return 0;
    }

    /**
     * Print an existing account's client setup — host, ports, username. Never
     * the password: Stalwart only stores a hash, so a lost password can only
     * be replaced (`mail:password <email>`), never recovered.
     */
    protected function showAccount(string $kubectl, string $ns, string $env, ?ConfigData $config, string $email): int
    {
        $accounts = $this->stalwartAccounts($kubectl, $ns);
        if ($accounts === null) {
            $this->laraKubeError('Could not connect to the Stalwart API.');

            return 1;
        }

        $account = null;
        foreach ($accounts as $a) {
            if (($a['emailAddress'] ?? ($a['name'].'@?')) === $email) {
                $account = $a;
                break;
            }
        }

        if ($account === null) {
            $this->laraKubeError("Account '{$email}' not found.");

            return 1;
        }

        $host = $this->resolveMailHostReadOnly($env, $config);

        $this->newLine();
        $this->line(" <fg=blue>{$email}</>");
        $this->line(' '.str_repeat('─', 40));
        $this->newLine();
        $this->line('  <fg=gray>Name:</>  '.($account['description'] ?? $account['name']));
        $this->line('  <fg=gray>Role:</>  '.($account['roles']['@type'] ?? 'User'));
        $this->newLine();

        if ($host) {
            $this->line('  <fg=yellow>Apple Mail / Thunderbird:</>');
            $this->line("     IMAP:  <fg=blue>{$host}</>  port <fg=blue>993</>  (SSL/TLS)   ·   SMTP:  <fg=blue>{$host}</>  port <fg=blue>465</>  (SSL/TLS)");
            $this->line("     Username: <fg=blue>{$email}</>");
        }
        $this->newLine();
        $this->line("  <fg=gray>Password isn't recoverable — Stalwart only stores a hash.</>");
        $this->line('  <fg=gray>Lost it? Issue a new one:</>');
        $this->line("  <fg=blue>larakube mail:password {$email}</>");
        $this->newLine();

        return 0;
    }
}
