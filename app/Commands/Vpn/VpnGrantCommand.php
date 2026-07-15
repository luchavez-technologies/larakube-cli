<?php

namespace App\Commands\Vpn;

use App\State;
use App\Traits\EmitsJsonOutput;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\InteractsWithVpn;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsCommandOptions;

use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class VpnGrantCommand extends Command
{
    use EmitsJsonOutput, InteractsWithProjectConfig, InteractsWithVpn, LaraKubeOutput, ReadsCommandOptions;

    protected $signature = 'vpn:grant
        {environment=local : Environment whose NetBird VPN to grant access to}
        {--name= : The teammate this key is for (labels it so vpn:users/vpn:revoke can find it)}
        {--context= : Target a specific kube-context (defaults to the environment\'s saved cloud target)}
        {--reusable : Allow multiple devices to join with this same key (default: single-use, one device)}
        {--ephemeral : Auto-remove the peer once it goes stale/disconnects (for a CI runner, not a person\'s device)}
        {--expires=365 : Days until the key expires}
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = 'Mint a NetBird VPN setup key for a teammate to join (re-run to issue another)';

    /** @var array<string, mixed> */
    private array $result = [];

    public function handle(): int
    {
        if ($this->flag('json') || $this->isAiAgent()) {
            $this->enableJsonMode();
        }

        $exit = $this->grant();

        if (State::$jsonMode) {
            $this->jsonOutput($exit === 0
                ? array_merge(['success' => true], $this->result, ['error' => null])
                : ['success' => false, 'error' => State::$lastError ?? 'Grant did not complete.']);
        }

        return $exit;
    }

    private function grant(): int
    {
        $this->renderHeader();

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

        $pat = $this->fetchVpnPat($kubectl, $ns);
        if ($pat === null) {
            $this->laraKubeError("No NetBird admin token found — re-run `larakube vpn:init {$env}` to bootstrap auth.");

            return 1;
        }

        $name = (string) $this->option('name');
        if ($name === '') {
            if ($this->flag('no-interaction')) {
                $this->laraKubeError('No teammate name — pass --name= when running non-interactively.');

                return 1;
            }
            $name = (string) text(label: 'Teammate name', placeholder: 'lloyd', required: true);
        }

        $reusable = (bool) $this->option('reusable');
        $ephemeral = (bool) $this->option('ephemeral');
        $days = max(1, (int) $this->option('expires'));

        $minted = $this->mintVpnSetupKey($host, $pat, $name, $reusable, $days, $ephemeral);
        if ($minted === null) {
            $this->laraKubeError('Could not mint the setup key — see the NetBird server output above.');

            return 1;
        }
        $key = (string) $minted['key'];
        $this->registerSecret($key);

        if (State::$jsonMode) {
            $this->result = [
                'name' => $name,
                'key' => $key,
                'reusable' => $reusable,
                'expires' => $minted['expires'] ?? null,
                'managementUrl' => "https://{$host}",
            ];

            return 0;
        }

        $this->laraKubeInfo("✅ Minted a NetBird setup key for '{$name}' — ".($reusable ? 'reusable across multiple devices' : 'single-use, one device').'.');
        $this->newLine();
        $this->line("  <fg=gray>Setup key:</> <fg=cyan>{$key}</>");
        $this->line('  <fg=gray>Expires:</> '.($minted['expires'] ?? '?'));
        $this->newLine();
        $this->laraKubeWarn('Deliver this key SECURELY — not committed, not pasted in a public channel.');
        $this->newLine();
        $this->line('  They install the NetBird client (<fg=cyan>https://netbird.io/download</>), then run:');
        $this->newLine();
        $this->line("  <fg=yellow>netbird up --management-url https://{$host} --setup-key {$key}</>");
        $this->newLine();
        $this->line('  <fg=gray>(Windows: an elevated/Admin PowerShell. macOS/Linux: prefix with `sudo`.)</>');
        $this->laraKubeNewLine();
        $this->line("  <fg=gray>List active keys:</> <fg=yellow>larakube vpn:users {$env}</>  <fg=gray>· Revoke:</> <fg=yellow>larakube vpn:revoke {$env} --name {$name}</>");

        return 0;
    }
}
