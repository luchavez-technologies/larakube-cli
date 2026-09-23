<?php

namespace App\Commands\Tls;

use App\Services\Kubectl;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\LaraKubeOutput;
use App\Traits\ProvisionsK3sNode;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\ResolvesToolEnvironment;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

/**
 * Switch a cloud cluster's Let's Encrypt certificates back to the HTTP
 * challenge. Leaves `dns:init`'s own tokens and every stored certificate alone.
 */
class TlsRemoveCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, LaraKubeOutput, ProvisionsK3sNode, RequiresFlagsWhenNonInteractive, ResolvesToolEnvironment;

    protected $signature = 'tls:remove
        {environment? : The cloud environment to switch back to the HTTP challenge}
        {--context=  : Target a specific kube-context}
        {--force     : Skip the confirmation prompt}';

    protected $description = 'Switch Let\'s Encrypt back to the HTTP challenge (proxied hosts can no longer renew)';

    public function handle(): int
    {
        $this->renderHeader();

        $env = $this->resolveToolEnvironment('TLS');

        if ($env === 'local') {
            $this->laraKubeError('Local clusters use the LaraKube Local CA, not Let\'s Encrypt.');

            return 1;
        }

        $context = $this->resolveToolContext($env, (string) $this->option('context') ?: null);
        if ($context === null || $context === '') {
            $this->laraKubeError("No kube-context resolved for '{$env}'. Pass --context=.");

            return 1;
        }

        $kubectl = Kubectl::forContext($context)->prefix();

        if (! $this->traefikUsesDnsChallenge($kubectl)) {
            $this->laraKubeInfo("Let's Encrypt on '{$env}' already uses the HTTP challenge. Nothing to change.");

            return 0;
        }

        $proxied = $this->proxiedHosts($this->clusterIngresses($kubectl));
        if ($proxied !== []) {
            $this->laraKubeError('These hosts are proxied through Cloudflare, and the HTTP challenge can\'t renew them:');
            foreach ($proxied as $host) {
                $this->line("  <fg=red>•</> {$host}");
            }
            $this->line('  <fg=gray>Switch them to DNS-only first, then try again.</>');

            return 1;
        }

        if (! $this->confirmDestructive([
            "Traefik on '{$env}' goes back to the HTTP challenge for Let's Encrypt.",
            'Existing certificates are kept. Traefik restarts: expect a few seconds of errors on every site.',
        ])) {
            return 0;
        }

        $ip = $this->resolveTraefikIngressIp($context);
        if ($ip === null) {
            $this->laraKubeError("Could not determine this cluster's ingress IP. Pass --context= for the right cluster.");

            return 1;
        }

        // Re-render first: Traefik still references the Secret until it rolls.
        if (! $this->deployTraefik($context, $ip, force: true, dnsChallenge: false)) {
            $this->laraKubeError('Traefik did not come back up with the HTTP challenge. The token Secret was kept.');

            return 1;
        }

        Process::run("{$kubectl} delete secret ".self::TRAEFIK_ACME_TOKEN_SECRET.' -n traefik --ignore-not-found');

        $this->laraKubeInfo("✅ Let's Encrypt on '{$env}' is back on the HTTP challenge.");

        return 0;
    }
}
