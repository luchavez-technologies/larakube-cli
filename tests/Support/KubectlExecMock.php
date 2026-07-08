<?php

/**
 * Historical guard, now covering a much narrower surface. Every kubectl-
 * touching file under App\Traits AND App\Commands (including App\Mcp\Tools
 * and App\Data\ConfigData) has been migrated from raw exec()/shell_exec()/
 * passthru() to Illuminate\Support\Facades\Process (which uses proc_open(),
 * NOT the unqualified exec()/shell_exec() functions this file overrides) —
 * their own tests use Process::fake() directly, not this mock. This file's
 * override only still matters for whatever NEW App\Traits code hasn't been
 * migrated yet — check with
 * `grep -rn "shell_exec(\|passthru(\|exec(" app/Traits/ app/Commands/`
 * before assuming this list is exhaustive.
 *
 * Deliberately left as raw exec()/shell_exec()/passthru() everywhere — a
 * different migration shape, not an oversight:
 *   - Any `sudo ...` write (InteractsWithHosts' /etc/hosts sync,
 *     InteractsWithTrust's system trust-store install/remove and dnsmasq
 *     /etc writes, InteractsWithOpenTofu's package-manager installs,
 *     InteractsWithDocker's k3s containerd sideload, ClusterSetupCommand's
 *     native k3s install + sudoers rule, UpCommand/SetupCommand's WSL2
 *     Docker Engine installer, UninstallCommand/UpdateCommand's binary
 *     swap) — genuinely interactive sudo password entry.
 *   - `certutil.exe`/`powershell.exe -Verb RunAs` calls across the WSL→
 *     Windows boundary (InteractsWithTrust, InteractsWithHosts) — UAC
 *     elevation is unreliable to automate from WSL.
 *   - Real interactive TTY sessions: `kubectl exec -it` (ExecCommand,
 *     ShellCommand, TestCommand's `php artisan test` run), `k9s` itself
 *     (K9sCommand), `gh auth login`/`gh auth switch` (GhaLoginCommand,
 *     GhaSwitchCommand), the generic `gh {args}` proxy (GhCommand), and
 *     `docker run --rm -it ... laravel new ...` (NewCommand).
 *   - Blocking-forever follows: `kubectl logs -f` (LogsCommand,
 *     Traefik\LogsCommand) and `kubectl port-forward` (Traefik\
 *     DashboardCommand, TunnelCommand's parallel port-forwards) — no exit
 *     until Ctrl-C, fundamentally incompatible with Process::run()'s
 *     wait-for-exit model.
 *
 * PHP resolves an unqualified exec()/shell_exec() call against the CALLING
 * CODE's own file namespace — App\Traits, since that's where each trait is
 * declared — regardless of which class composes it. Left unmocked, any test
 * that reaches unmigrated kubectl code shells out to the REAL kubectl. On a
 * dev machine with real kube-contexts configured, that's not a quiet no-op:
 * it can be a REAL interactive `select()` prompt blocking on real terminal
 * input, not a test failure — the tests still pass, they just never return
 * control to the terminal.
 *
 * Default-safe rather than opt-in: any `kubectl ...` command is faked empty
 * (no contexts, unreachable) unless a test explicitly opts in via
 * $GLOBALS['mock_kube_exec_callback']. Every other exec() call in App\Traits
 * (ManagesLocalCa/GeneratesOfflineCertificates' openssl — now Process::run()
 * too, but unfaked in their tests so it still shells out for real, same as
 * before; ClusterContextTest's own git/shell_exec mock) passes through to
 * the real \exec() untouched — this must stay narrowly scoped to kubectl,
 * not a blanket App\Traits exec() override, or it breaks unrelated tests
 * that need real exec() (confirmed: an earlier, broader version of this mock
 * broke TrustCheckCommandTest/ManagesLocalCaTest/BundleInstallOptionsTest,
 * all of which call real openssl in a different App\Traits-namespaced trait).
 */

namespace App\Traits {
    function exec($command, &$output = null, &$result_code = null)
    {
        if (isset($GLOBALS['mock_kube_exec_callback']) && is_callable($GLOBALS['mock_kube_exec_callback'])) {
            return ($GLOBALS['mock_kube_exec_callback'])($command, $output, $result_code);
        }

        if (str_contains($command, 'kubectl')) {
            $output = [];
            $result_code = 1;

            return null;
        }

        return \exec($command, $output, $result_code);
    }
}
