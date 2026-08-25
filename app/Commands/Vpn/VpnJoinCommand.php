<?php

namespace App\Commands\Vpn;

use App\Traits\DetectsWsl;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithOs;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\InteractsWithVpn;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

class VpnJoinCommand extends Command
{
    use DetectsWsl, InteractsWithClusterContext, InteractsWithOs, InteractsWithProjectConfig, InteractsWithVpn, LaraKubeOutput;

    protected $signature = 'vpn:join
        {environment=local : Environment whose NetBird VPN to join}
        {--context= : Target a specific kube-context (defaults to current context)}
        {--sso : Authenticate via Zitadel SSO instead of the shared setup key (run `larakube sso:wire vpn` first)}';

    protected $description = "Join this machine to a project's NetBird VPN";

    public function handle(): int
    {
        $this->renderHeader();
        $this->laraKubeInfo('LaraKube VPN Join');
        $this->newLine();

        // Checked before isLinux() — WSL2 reports PHP_OS_FAMILY === 'Linux' too.
        if ($this->isWsl()) {
            $this->laraKubeError("vpn:join can't run inside WSL2 — a VPN client needs to attach to Windows' network stack, not the WSL2 Linux subsystem's.");
            $this->line('  Install the NetBird Windows client from inside Windows instead: <fg=cyan>https://netbird.io/download</>');

            return 1;
        }

        if (! $this->isLinux() && ! $this->isDarwin()) {
            $this->laraKubeError('vpn:join supports Linux and macOS only.');

            return 1;
        }

        $env = (string) $this->argument('environment');
        $config = $this->getProjectConfig();
        $kubectl = $this->vpnKubectl($this->resolveVpnContext($env, $config));
        $ns = $this->vpnNamespace();

        if (! $this->isVpnInstalled($kubectl, $ns)) {
            $this->laraKubeError("NetBird VPN isn't installed for '{$env}'.");
            $this->line("  Run <fg=yellow>larakube vpn:init {$env}</> first.");

            return 1;
        }

        $host = $this->resolveVpnHostReadOnly($env, $config);

        if (! $host) {
            $this->laraKubeError("No NetBird VPN host configured for '{$env}'.");

            return 1;
        }

        // Checked before installNetBirdClient() — no point installing the
        // client (which may shell out to a real curl|sh install script) only
        // to then fail on a precondition that was already knowable.
        if ($this->option('sso') && ! Process::run("{$kubectl} get secret netbird-oidc -n {$ns}")->successful()) {
            $this->laraKubeError("NetBird isn't wired to SSO yet — run `larakube sso:wire vpn {$env}` first.");

            return 1;
        }

        if (! $this->installNetBirdClient()) {
            $this->laraKubeError('NetBird client install failed — see the output above.');

            return 1;
        }

        if ($this->option('sso')) {
            // Additive alternative to the setup-key flow below — never the
            // default even once wired, since VPN is the access-of-last-resort
            // layer and a wrong default here risks confusing failures
            // precisely when someone most needs connectivity.
            $this->laraKubeInfo('Opening a browser to sign in via Zitadel SSO...');
            // Omitting --setup-key is what triggers NetBird's own automatic
            // browser-based SSO login when the management server has an
            // OIDC provider registered.
            passthru('sudo netbird up --management-url https://'.escapeshellarg($host), $exitCode);
        } else {
            $key = $this->fetchVpnSetupKey($kubectl, $ns);
            if ($key === null) {
                $this->laraKubeError("No NetBird setup key found — re-run `larakube vpn:init {$env}` to bootstrap auth, or mint one manually in the NetBird dashboard.");

                return 1;
            }
            $this->registerSecret($key);

            $this->laraKubeInfo("Joining NetBird VPN at {$host}...");
            passthru('sudo netbird up --setup-key '.escapeshellarg($key)." --management-url https://{$host}", $exitCode);
        }

        if ($exitCode !== 0) {
            $this->laraKubeError('`netbird up` failed — see the output above.');

            return 1;
        }

        $this->newLine();
        $this->laraKubeInfo('✅ Connected.');
        $this->line('  This machine can now privately reach services deployed only inside the cluster');
        $this->line('  (anything not exposed via a public Ingress) — nothing else changes automatically.');

        return 0;
    }

    /** Install the official NetBird client if it isn't already on PATH. */
    protected function installNetBirdClient(): bool
    {
        if (trim((string) shell_exec('command -v netbird 2>/dev/null')) !== '') {
            return true;
        }

        $this->laraKubeInfo('Installing the NetBird client...');
        passthru('curl -fsSL https://pkgs.netbird.io/install.sh | sh', $exitCode);

        return $exitCode === 0;
    }
}
