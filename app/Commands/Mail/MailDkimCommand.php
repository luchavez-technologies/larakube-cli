<?php

namespace App\Commands\Mail;

use App\Data\ConfigData;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithMail;
use App\Traits\InteractsWithStalwartApi;
use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

/**
 * Inspect (and repair) a domain's DKIM signing keys.
 *
 * Exists because Stalwart mints BOTH an RSA and an Ed25519 key when a domain is
 * added through the admin wizard, and then stamps both on outbound mail. SES
 * rejects the result with a 554 duplicate-header bounce. The fix is to keep one
 * algorithm — this project standardises on RSA — but domains get added outside
 * the CLI, so the prune has to be re-runnable rather than a one-shot buried in
 * `mail:relay`.
 */
class MailDkimCommand extends Command
{
    use InteractsWithClusterContext, InteractsWithMail, InteractsWithStalwartApi, LaraKubeOutput;

    protected $signature = 'mail:dkim
        {environment=local : Environment whose mail server to target}
        {--context= : Target a specific kube-context}
        {--fix : Delete every Ed25519 signing key, leaving RSA only}';

    protected $description = 'Show DKIM signing keys and prune duplicates that cause 554 bounces';

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');
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

        if ($this->option('fix')) {
            $destroyed = $this->pruneEd25519($kubectl, $ns);

            if ($destroyed === null) {
                return 1;
            }
        }

        $signatures = $this->stalwartDkimSignatures($kubectl, $ns);

        if ($signatures === null) {
            $this->laraKubeError('Could not connect to the Stalwart API.');

            return 1;
        }

        if ($signatures === []) {
            $this->laraKubeInfo('No DKIM keys configured. Add a domain in the admin UI at Directory → Domains.');

            return 0;
        }

        $this->newLine();
        table(
            ['Domain', 'Selector', 'Algorithm', 'Stage'],
            array_map(fn (array $sig) => [
                $sig['domain'],
                $sig['selector'],
                $sig['isEd25519'] ? "<fg=yellow>{$sig['type']}</>" : $sig['type'],
                $sig['stage'],
            ], $signatures),
        );

        return $this->reportDuplicates($signatures, $env);
    }

    /**
     * @return int|null Number of keys destroyed, or null if Stalwart refused
     *                  the write (already reported to the operator).
     */
    protected function pruneEd25519(string $kubectl, string $ns): ?int
    {
        $destroyed = null;

        $this->withSpin('Pruning Ed25519 DKIM keys...', function () use ($kubectl, $ns, &$destroyed): void {
            $destroyed = $this->stalwartEnforceSingleRsaDkimSignature($kubectl, $ns);
        });

        if ($destroyed === null) {
            $this->laraKubeError('Could not prune DKIM keys — Stalwart did not accept the change.');

            return null;
        }

        $destroyed === 0
            ? $this->laraKubeInfo('Already RSA-only — no Ed25519 keys to remove.')
            : $this->laraKubeInfo("✅ Removed {$destroyed} Ed25519 signing key(s). Stalwart now signs with RSA only.");

        return $destroyed;
    }

    /**
     * @param  list<array{domain: string, stage: string, isEd25519: bool, ...}>  $signatures
     */
    protected function reportDuplicates(array $signatures, string $env): int
    {
        $duplicates = $this->stalwartDuplicateActiveDkim($signatures);

        if ($duplicates === []) {
            $this->laraKubeInfo('✅ Every domain signs with a single active key.');

            return 0;
        }

        $this->newLine();
        foreach ($duplicates as $domain => $count) {
            $this->laraKubeError("{$domain} has {$count} active signing keys — outbound mail carries {$count} DKIM-Signature headers.");
        }

        $this->newLine();
        $this->line('  <fg=gray>SES and some other relays reject this with a 554 duplicate-header bounce.</>');
        $this->line("  <fg=gray>Fix it with:</> <fg=blue>larakube mail:dkim {$env} --fix</>");
        $this->newLine();
        $this->line('  <fg=gray>After pruning, remove the stale selector\'s TXT record from DNS.</>');

        return 1;
    }
}
