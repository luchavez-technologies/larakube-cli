<?php

namespace App\Commands\Dns;

use App\Enums\ClusterTool;
use App\Exceptions\MissingFlagException;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithClusterIdentity;
use App\Traits\LaraKubeOutput;
use App\Traits\PromotesIngressDns;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

/**
 * Deploy one ExternalDNS instance per Cloudflare zone.
 *
 * Previously a singleton: fixed resource names, no `--domain-filter`, and a
 * hardcoded `--txt-owner-id=larakube`. Three consequences, all real:
 *
 *   1. A second `dns:init` overwrote the first, so one cluster could only ever
 *      manage one zone — and only with one Cloudflare account's token.
 *   2. With no domain filter and `--policy=sync`, ExternalDNS managed every
 *      zone the token could see and DELETED records it didn't recognise.
 *   3. Sharing one owner ID meant two clusters pointed at the same zone each
 *      treated the other's records as their own orphans and deleted them —
 *      records flapping between clusters indefinitely.
 *
 * Zones are keyed by domain, one instance each, with a per-(cluster, zone)
 * owner ID. State lives in the cluster, never in a project file: DNS is cluster
 * infrastructure and has nothing to do with any Laravel app.
 */
class DnsInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithClusterContext,
        InteractsWithClusterIdentity, LaraKubeOutput, PromotesIngressDns,
        RequiresFlagsWhenNonInteractive, ResolvesToolEnvironment, StreamsProcessOutput;

    protected $signature = 'dns:init
        {environment?        : Environment this install targets (a cloud env — ExternalDNS is not supported locally)}
        {--zone=             : The Cloudflare zone to manage, e.g. example.com}
        {--cloudflare-token= : API token for THIS zone (scope it to this zone only)}
        {--context=          : Target a specific kube-context}
        {--force             : Skip the confirmation prompt}';

    protected $description = 'Deploy an ExternalDNS instance for one Cloudflare zone';

    public function handle(): int
    {
        $this->renderHeader();

        $env = $this->resolveToolEnvironment(ClusterTool::DNS);

        if ($env === 'local') {
            $this->laraKubeError('ExternalDNS is only supported on cloud environments.');

            return 1;
        }

        $context = $this->resolveToolContext($env, (string) $this->option('context') ?: null);
        $kubectl = $this->contextKubectl($context);
        $ns = 'larakube-shared';

        $zone = $this->resolveZone();
        $slug = $this->zoneSlug($zone);

        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        // Must come after the namespace exists — the ID lives in a ConfigMap there.
        $clusterId = $this->clusterIdentity($kubectl);
        if ($clusterId === null) {
            $this->laraKubeError('Could not read or create this cluster\'s identity.');
            $this->line('  <fg=gray>Without it, two clusters would share an ExternalDNS owner ID and delete');
            $this->line('  each other\'s DNS records. Refusing to deploy.</>');

            return 1;
        }

        $ownerId = $this->dnsOwnerId($clusterId, $zone);
        $token = $this->resolveToken($zone);

        if (! $this->confirmDestructive([
            "ExternalDNS will manage the '{$zone}' zone from '{$env}':",
            "Records are created and DELETED to match this cluster's ingresses.",
            "Only records owned by {$ownerId} are touched.",
        ])) {
            return 0;
        }

        $this->withSpin("Syncing the Cloudflare token for {$zone}...", fn () => Process::run(
            "{$kubectl} create secret generic cloudflare-token-{$slug} -n {$ns} "
            .'--from-literal=token='.escapeshellarg($token).' '
            ."--dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        $manifest = view('k8s.dns.zone', [
            'namespace' => $ns,
            'zone' => $zone,
            'slug' => $slug,
            'ownerId' => $ownerId,
        ])->render();

        $this->line("  Applying ExternalDNS for {$zone}...");
        $this->newLine();

        $exit = $this->runStreaming(
            'echo '.escapeshellarg($manifest)." | {$kubectl} apply -f -",
            timeoutSeconds: 300,
        );

        if ($exit !== 0) {
            return $exit;
        }

        $this->newLine();
        $this->laraKubeInfo("✅ ExternalDNS is managing {$zone}.");
        $this->newLine();
        $this->line("  <fg=gray>Zone:</>       <fg=blue>{$zone}</>");
        $this->line("  <fg=gray>Owner ID:</>   <fg=blue>{$ownerId}</> <fg=gray>(this cluster only)</>");
        $this->line("  <fg=gray>Instance:</>   <fg=blue>external-dns-{$slug}</>");
        $this->newLine();
        $this->line('  <fg=gray>Add another zone (even in a different Cloudflare account):</>');
        $this->line("  <fg=blue>larakube dns:init {$env} --zone=other.example --cloudflare-token=…</>");
        $this->line('  <fg=gray>See everything this cluster manages:</> <fg=blue>larakube dns:list '.$env.'</>');
        $this->newLine();

        return 0;
    }

    /**
     * The zone this instance manages. Required — an unfiltered ExternalDNS is
     * the destructive configuration this command exists to avoid, so there is
     * deliberately no "all zones" default.
     */
    protected function resolveZone(): string
    {
        return $this->flagOrPrompt(
            'zone',
            fn () => text(
                label: 'Which Cloudflare zone should this cluster manage?',
                placeholder: 'example.com',
                required: true,
                hint: 'One instance per zone. Re-run for additional zones.',
            ),
            'the Cloudflare zone to manage',
            'larakube dns:init production --zone=example.com',
        );
    }

    /**
     * The API token for this zone. Never persisted to a project or global
     * config file — it goes straight into a per-zone cluster Secret, because a
     * zone-scoped credential belongs to the cluster that uses it, and different
     * zones legitimately come from different Cloudflare accounts.
     */
    protected function resolveToken(string $zone): string
    {
        $token = (string) ($this->option('cloudflare-token') ?? '');
        if ($token !== '') {
            return $token;
        }

        if ($this->cannotPrompt()) {
            throw new MissingFlagException(
                'cloudflare-token',
                "the Cloudflare API token for {$zone}",
                "larakube dns:init production --zone={$zone} --cloudflare-token=…",
            );
        }

        $this->newLine();
        $this->info("Create a Cloudflare API token scoped to {$zone}:");
        $this->line('  1. <fg=blue>https://dash.cloudflare.com/profile/api-tokens</>');
        $this->line('  2. Create Token → Create Custom Token');
        $this->line('  3. Permissions: <fg=yellow>Zone</> · <fg=yellow>DNS</> · <fg=yellow>Edit</>');
        $this->line("  4. Zone Resources: <fg=yellow>Include</> · <fg=yellow>Specific Zone</> · <fg=yellow>{$zone}</>");
        $this->line('     <fg=gray>Scoping to this one zone is a second line of defence behind</>');
        $this->line('     <fg=gray>--domain-filter, which this command always sets.</>');
        $this->newLine();

        return (string) text(label: "Cloudflare API token for {$zone}", required: true);
    }
}
