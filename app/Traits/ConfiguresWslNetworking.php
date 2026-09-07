<?php

namespace App\Traits;

use function Laravel\Prompts\confirm;

/**
 * Offer to enable WSL2 mirrored networking during `setup`. Mirrored mode makes
 * Windows and WSL share localhost, so the cluster ingress is reachable from the
 * Windows browser at a stable 127.0.0.1 — eliminating the churning-node-IP
 * problem that otherwise staleifies the Windows hosts entry (and forces another
 * admin-elevated re-sync) after every `wsl --shutdown`.
 *
 * Requires the composing command to also use DetectsWsl (detection),
 * CollectsReminders ($reminders) and LaraKubeOutput.
 */
trait ConfiguresWslNetworking
{
    use DetectsWsl;

    /**
     * Return $ini with `networkingMode=mirrored` guaranteed inside a [wsl2]
     * section. Idempotent and non-destructive of other settings:
     *  - replaces an existing networkingMode= line (any value) in place,
     *  - or inserts one directly beneath an existing [wsl2] header,
     *  - or appends a fresh [wsl2] section when there is none.
     * Pure string transform, so it's unit-testable without the filesystem.
     */
    public function withMirroredNetworking(string $ini): string
    {
        $entry = 'networkingMode=mirrored';

        // 1) A networkingMode key already exists → set its value (idempotent).
        //    [ \t]* (not \s*) so it never swallows preceding blank lines.
        if (preg_match('/^[ \t]*networkingMode[ \t]*=/mi', $ini) === 1) {
            return preg_replace('/^[ \t]*networkingMode[ \t]*=.*$/mi', $entry, $ini, 1) ?? $ini;
        }

        $normalized = preg_replace('/\r\n/', "\n", $ini) ?? $ini;

        // 2) A [wsl2] header exists → insert the key on the line right after it.
        if (preg_match('/^[ \t]*\[wsl2\][ \t]*$/mi', $normalized) === 1) {
            return preg_replace('/^([ \t]*\[wsl2\][ \t]*)$/mi', "$1\n".$entry, $normalized, 1) ?? $normalized;
        }

        // 3) No [wsl2] section → append one (keeping any existing content intact).
        $prefix = trim($normalized) === '' ? '' : rtrim($normalized)."\n\n";

        return $prefix."[wsl2]\n".$entry."\n";
    }

    /**
     * On WSL, ensure mirrored networking is enabled — writing ~/.wslconfig on the
     * Windows side if the user agrees. No-op off WSL, when it's already active, or
     * when the Windows profile can't be located. Applying it needs a one-time
     * `wsl --shutdown`, surfaced as a reminder rather than run here: shutting WSL
     * down mid-setup would kill this very process.
     */
    protected function ensureMirroredNetworking(): void
    {
        if (! $this->isWsl()) {
            return;
        }

        if ($this->mirroredNetworkingActive()) {
            $this->laraKubeInfo('WSL mirrored networking already active — the Windows browser reaches the cluster at a stable 127.0.0.1.');

            return;
        }

        // Already enabled on a previous run but not yet live — don't re-ask the
        // question (that would make a re-run non-idempotent); just re-surface the
        // one-time restart it still needs.
        if ($this->mirroredNetworkingRequested()) {
            $this->laraKubeInfo('WSL mirrored networking is configured in ~/.wslconfig but not active yet.');
            $this->reminders[] = 'Run <fg=cyan>wsl --shutdown</> from Windows PowerShell, then reopen your terminal — this activates WSL mirrored networking (a stable 127.0.0.1 for the Windows browser).';

            return;
        }

        $this->laraKubeInfo('WSL networking: mirrored mode is recommended.');
        $this->line('  <fg=gray>It shares localhost between Windows and WSL, so your Windows browser reaches the cluster at a stable</> <fg=cyan>127.0.0.1</><fg=gray>.</>');
        $this->line('  <fg=gray>Without it, WSL\'s IP changes on every reboot and the Windows hosts entry goes stale — needing another admin sync.</>');

        if (! confirm(label: 'Enable WSL mirrored networking now? (writes ~/.wslconfig)', default: true)) {
            return;
        }

        if (! $this->enableMirroredNetworkingInWslConfig()) {
            $this->laraKubeWarn('Could not update ~/.wslconfig automatically. Add this under a [wsl2] section manually:');
            $this->line('     <fg=cyan>networkingMode=mirrored</>');

            return;
        }

        $this->laraKubeInfo('✅ Enabled mirrored networking in ~/.wslconfig.');
        $this->reminders[] = 'Run <fg=cyan>wsl --shutdown</> from Windows PowerShell, then reopen your terminal — this activates WSL mirrored networking (a stable 127.0.0.1 for the Windows browser).';
    }

    /** Patch (or create) ~/.wslconfig so [wsl2] carries networkingMode=mirrored. */
    protected function enableMirroredNetworkingInWslConfig(): bool
    {
        $path = $this->wslConfigPath();
        if ($path === null) {
            return false;
        }

        $current = is_file($path) ? (string) file_get_contents($path) : '';

        return file_put_contents($path, $this->withMirroredNetworking($current)) !== false;
    }
}
