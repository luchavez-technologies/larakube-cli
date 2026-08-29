<?php

namespace App\Commands\Vpn;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;
use App\Traits\InteractsWithVpn;

class VpnShowCommand extends AbstractToolShowCommand
{
    use InteractsWithVpn;

    /** Below this many days remaining, the countdown is a warning rather than a note. */
    protected const WARN_WITHIN_DAYS = 30;

    protected function tool(): ClusterTool
    {
        return ClusterTool::VPN;
    }

    /**
     * Surface how long the stored NetBird credentials have left.
     *
     * vpn:init mints the PAT and the setup key in a single call, so they expire
     * within milliseconds of each other — and an expired PAT cannot mint its own
     * replacement, so there is no API path back in. `vpn:rotate` fixes that, but
     * only if someone runs it in time; without a countdown somewhere the first
     * signal is every VPN command failing at once.
     *
     * Best-effort by design: :show is a read-only inspection command, so an
     * unreachable API or a missing PAT stays silent rather than turning a status
     * listing into an error.
     */
    protected function afterTable(?string $host, string $env, string $instance = ''): void
    {
        if ($host === null) {
            return;
        }

        $kubectl = $this->vpnKubectl($this->resolveVpnContext($env, $this->getProjectConfig()));
        $ns = $this->vpnNamespace();

        // Ahead of the credential countdown deliberately: a dead PAT returns
        // early below, and a broken single-account invariant is exactly the kind
        // of thing you want reported while the rest of the command is failing.
        $this->renderSingleAccountState($kubectl, $ns);

        $pat = $this->fetchVpnPat($kubectl, $ns);

        if ($pat === null) {
            return;
        }

        $expiries = $this->vpnCredentialExpiryDays($host, $pat);

        if ($expiries === []) {
            return;
        }

        $this->newLine();

        foreach ($expiries as $label => $days) {
            if ($days < 0) {
                $this->line("  <fg=red>✗ {$label} EXPIRED</> <fg=gray>".abs($days).' days ago</>');

                continue;
            }

            if ($days <= self::WARN_WITHIN_DAYS) {
                $this->line("  <fg=yellow>⚠ {$label} expires in {$days} day".($days === 1 ? '' : 's').'</>');

                continue;
            }

            $this->line("  <fg=gray>{$label} expires in {$days} days</>");
        }

        $soonest = min($expiries);

        if ($soonest <= self::WARN_WITHIN_DAYS) {
            $this->line('  <fg=gray>Renew both with</> <fg=blue>larakube vpn:rotate '.$env.'</><fg=gray> — it needs the CURRENT PAT to still be valid.</>');
        }

        $this->newLine();
    }

    /**
     * Report whether one company still means one network here.
     *
     * Silent when enabled: it is the expected state, and :show is already dense.
     * Silent when unknown too — the startup line ages out of a long-running
     * pod's log window, and "cannot tell" is not worth a line of its own.
     */
    protected function renderSingleAccountState(string $kubectl, string $ns): void
    {
        $state = $this->vpnSingleAccountState($kubectl, $ns);

        if ($state === null || $state['enabled']) {
            return;
        }

        $this->newLine();
        $this->line('  <fg=red>✗ Single-account mode OFF</>');
        $this->line("  <fg=yellow>Management counted {$state['accounts']} accounts.</>");
        $this->line('  <fg=gray>New SSO logins get their own isolated account and cannot reach this cluster.</>');
    }
}
