<?php

namespace App\Commands\Dns;

use App\Enums\ClusterTool;
use App\Exceptions\MissingFlagException;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithCloudflareApi;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithClusterIdentity;
use App\Traits\InteractsWithDnsZones;
use App\Traits\LaraKubeOutput;
use App\Traits\PromotesIngressDns;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

/**
 * Deploy one ExternalDNS instance per dns:init GROUP — a stable name covering
 * one or more Cloudflare zones that share a single API token.
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
 * That was fixed with a strict one-instance-per-zone design. This command now
 * relaxes that one further step: ExternalDNS itself already supports several
 * `--domain-filter` values in one process — the only real constraint is that
 * one instance holds exactly one provider credential (one Cloudflare token).
 * So a --group= now means "one ExternalDNS instance, one token, N zones that
 * token can see" — --zone= (repeatable) is entirely optional: omit it and
 * every zone the given token has access to is discovered and managed. This
 * is what actually lets `ourfridays.com` + `larakube.app` (one Cloudflare
 * account) run as one Deployment instead of two, while a genuinely separate
 * account (a different token) stays a fully separate instance — the
 * isolation that matters is ownership (--txt-owner-id, one per group) and
 * scope (--domain-filter, still one per zone), never process count.
 *
 * State lives in the cluster, never in a project file: DNS is cluster
 * infrastructure and has nothing to do with any Laravel app.
 */
class DnsInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithCloudflareApi,
        InteractsWithClusterContext, InteractsWithClusterIdentity, InteractsWithDnsZones,
        LaraKubeOutput, PromotesIngressDns, RequiresFlagsWhenNonInteractive,
        ResolvesToolEnvironment, StreamsProcessOutput;

    protected $signature = 'dns:init
        {environment?        : Environment this install targets (a cloud env — ExternalDNS is not supported locally)}
        {--cloudflare-token= : API token — every zone it can see is discovered and managed, unless --zone= narrows that}
        {--zone=*            : Optional — restrict to a subset of what the token can see. Omit to manage every zone the token has access to.}
        {--group=            : Stable name for this instance. Default: the sole zone\'s own slug (unchanged single-zone behavior) — required when 2+ zones are in scope}
        {--context=          : Target a specific kube-context}
        {--force             : Skip the confirmation prompt}';

    protected $description = 'Deploy an ExternalDNS instance for one or more Cloudflare zones sharing a token';

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

        $token = $this->resolveToken($kubectl, $ns);

        $zones = $this->resolveZones($token);
        if ($zones === []) {
            return 1;
        }

        $group = $this->resolveGroup($zones);
        if ($group === false) {
            return 1;
        }

        $groupSlug = $this->groupSlug($zones, $group);

        if (! $this->checkForConflicts($kubectl, $zones, $groupSlug)) {
            return 1;
        }

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

        $ownerId = $this->dnsOwnerId($clusterId, $groupSlug);
        $zoneList = implode(', ', $zones);

        if (! $this->confirmDestructive([
            "ExternalDNS will manage {$zoneList} from '{$env}':",
            "Records are created and DELETED to match this cluster's ingresses.",
            "Only records owned by {$ownerId} are touched.",
        ])) {
            return 0;
        }

        $this->withSpin("Syncing the Cloudflare token for {$groupSlug}...", fn () => Process::run(
            "{$kubectl} create secret generic cloudflare-token-{$groupSlug} -n {$ns} "
            .'--from-literal=token='.escapeshellarg($token).' '
            ."--dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        $manifest = view('k8s.dns.zone', [
            'namespace' => $ns,
            'zones' => $zones,
            'slug' => $groupSlug,
            'ownerId' => $ownerId,
        ])->render();

        $this->line("  Applying ExternalDNS for {$zoneList}...");
        $this->newLine();

        $exit = $this->runStreaming(
            'echo '.escapeshellarg($manifest)." | {$kubectl} apply -f -",
            timeoutSeconds: 300,
        );

        if ($exit !== 0) {
            return $exit;
        }

        $this->newLine();
        $this->laraKubeInfo("✅ ExternalDNS is managing {$zoneList}.");
        $this->newLine();
        $this->line("  <fg=gray>Zones:</>      <fg=blue>{$zoneList}</>");
        $this->line("  <fg=gray>Owner ID:</>   <fg=blue>{$ownerId}</> <fg=gray>(this cluster only)</>");
        $this->line("  <fg=gray>Instance:</>   <fg=blue>external-dns-{$groupSlug}</>");
        $this->newLine();
        $this->line('  <fg=gray>A zone with a different Cloudflare account (different token) needs its own group:</>');
        $this->line("  <fg=blue>larakube dns:init {$env} --cloudflare-token=…</>");
        $this->line('  <fg=gray>See everything this cluster manages:</> <fg=blue>larakube dns:list '.$env.'</>');
        $this->newLine();

        return 0;
    }

    /**
     * Every stored Cloudflare token, keyed by its group slug.
     *
     * @return array<string, string>
     */
    protected function storedCloudflareTokens(string $kubectl, string $ns): array
    {
        $names = trim(Process::run(
            "{$kubectl} get secret -n {$ns} -o name --no-headers --ignore-not-found",
        )->output());

        $tokens = [];

        foreach (preg_split('/\s+/', $names) ?: [] as $name) {
            $name = str_replace('secret/', '', trim($name));

            if (! str_starts_with($name, 'cloudflare-token-')) {
                continue;
            }

            $value = $this->readClusterSecretKey($kubectl, $ns, $name, 'token');

            if ($value !== null && $value !== '') {
                $tokens[substr($name, strlen('cloudflare-token-'))] = $value;
            }
        }

        return $tokens;
    }

    /**
     * The Cloudflare API token driving discovery. No zone is known yet at
     * this point — the token's own Cloudflare-side scope IS what determines
     * which zone(s) this instance ends up managing (see resolveZones()).
     */
    protected function resolveToken(string $kubectl, string $ns): string
    {
        $token = (string) ($this->option('cloudflare-token') ?? '');
        if ($token !== '') {
            return $token;
        }

        // Reuse what is already stored, so this command is re-runnable like
        // every other :init. Without it, re-applying the manifest — to pick up
        // a new flag, say — demanded the credential again and then OVERWROTE
        // the stored one with whatever was typed; a token with a different zone
        // scope would also resolve to a different group slug, standing up a
        // SECOND instance with its own --txt-owner-id against the same zones.
        //
        // Only when exactly one is stored: the slug is derived from the zones a
        // token can see, so with several there is no way to know which one this
        // run means without being told via --cloudflare-token= or --group=.
        $stored = $this->storedCloudflareTokens($kubectl, $ns);

        if (count($stored) === 1) {
            $slug = array_key_first($stored);
            $this->laraKubeInfo("Reusing the stored Cloudflare token for '{$slug}'.");
            $this->line('  <fg=gray>Pass</> <fg=blue>--cloudflare-token=</> <fg=gray>to replace it.</>');

            return $stored[$slug];
        }

        if ($this->cannotPrompt()) {
            throw new MissingFlagException(
                'cloudflare-token',
                'the Cloudflare API token for the zone(s) to manage',
                'larakube dns:init production --cloudflare-token=…',
            );
        }

        $this->newLine();
        $this->info('Create a Cloudflare API token scoped to the zone(s) you want this instance to manage:');
        $this->line('  1. <fg=blue>https://dash.cloudflare.com/profile/api-tokens</>');
        $this->line('  2. Create Token → Create Custom Token');
        $this->line('  3. Permissions: <fg=yellow>Zone</> · <fg=yellow>DNS</> · <fg=yellow>Edit</>');
        $this->line('  4. Zone Resources: <fg=yellow>Include</> · one row per zone this instance should manage');
        $this->line('     <fg=gray>Every zone this token can see is what gets discovered and managed below —</>');
        $this->line('     <fg=gray>scope it deliberately, the same second line of defence --domain-filter always adds.</>');
        $this->newLine();

        return (string) text(label: 'Cloudflare API token', required: true);
    }

    /**
     * The zone(s) this instance will manage: every zone the token can see,
     * narrowed to an explicit --zone= subset when given. Returns [] (having
     * already printed its own error) on a bad token, a token with no zone
     * access, or a --zone= naming something the token can't actually see —
     * never guesses or silently drops an unrecognised zone.
     *
     * @return list<string>
     */
    protected function resolveZones(string $token): array
    {
        $discovered = array_values($this->cloudflareListZones($token));

        if ($discovered === []) {
            $this->laraKubeError("This token has no zone access — check it's valid and scoped correctly in Cloudflare.");

            return [];
        }

        $requested = array_values(array_filter((array) ($this->option('zone') ?: [])));

        if ($requested === []) {
            return $discovered;
        }

        $missing = array_diff($requested, $discovered);
        if ($missing !== []) {
            $this->laraKubeError("This token can't see: ".implode(', ', $missing));
            $this->line('  <fg=gray>Zones it can see: </>'.implode(', ', $discovered));

            return [];
        }

        return $requested;
    }

    /**
     * The stable --group= name for this instance. Required whenever 2+
     * zones are in scope — never silently derived from the zone set (see
     * groupSlug()'s own docblock for why). false signals "already errored,
     * abort" — the same tri-state shape resolveInstanceForTool() uses
     * elsewhere in this codebase, kept consistent rather than inventing a
     * second convention for the same kind of decision.
     *
     * @param  list<string>  $zones
     */
    protected function resolveGroup(array $zones): string|false|null
    {
        $group = (string) ($this->option('group') ?: '');
        if ($group !== '') {
            return $group;
        }

        if (count($zones) === 1) {
            return null;
        }

        if ($this->cannotPrompt()) {
            throw new MissingFlagException(
                'group',
                'a stable name for this multi-zone instance ('.implode(', ', $zones).')',
                'larakube dns:init production --group=shared --cloudflare-token=…',
            );
        }

        return (string) text(
            label: 'This token manages '.count($zones).' zones ('.implode(', ', $zones).') — name this instance',
            placeholder: 'shared',
            required: true,
        );
    }

    /**
     * Refuse to create/update a group that would claim a zone another,
     * differently-named instance already manages — the exact `--txt-owner-id`
     * collision the original multi-zone rebuild fixed, reachable again here
     * if two groups both listed the same zone.
     *
     * @param  list<string>  $zones
     */
    protected function checkForConflicts(string $kubectl, array $zones, string $groupSlug): bool
    {
        $installed = $this->installedDnsZones($kubectl);

        foreach ($zones as $zone) {
            foreach ($installed as $entry) {
                if ($entry['zone'] === $zone && $entry['slug'] !== $groupSlug) {
                    $this->laraKubeError(
                        "'{$zone}' is already managed by 'external-dns-{$entry['slug']}' — remove it there first "
                        ."(dns:remove --zone={$zone}), or pass --group={$entry['slug']} here to fold it into that instance.",
                    );

                    return false;
                }
            }
        }

        return true;
    }
}
