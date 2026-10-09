<?php

namespace App\Commands\Dns;

use App\Commands\Tool\AbstractToolInitCommand;
use App\Enums\ClusterTool;
use App\Enums\DnsProvider;
use App\Exceptions\MissingFlagException;
use App\Services\Kubectl;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithCloudflareApi;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithClusterIdentity;
use App\Traits\InteractsWithDnsZones;
use App\Traits\InteractsWithRoute53Api;
use App\Traits\LaraKubeOutput;
use App\Traits\PromotesIngressDns;
use App\Traits\ReadsStoredDnsCredentials;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Deploy one ExternalDNS instance per tool:init --tool=external-dns GROUP — a stable name covering
 * one or more Cloudflare zones that share a single API token.
 *
 * Previously a singleton: fixed resource names, no `--domain-filter`, and a
 * hardcoded `--txt-owner-id=larakube`. Three consequences, all real:
 *
 *   1. A second `tool:init --tool=external-dns` overwrote the first, so one cluster could only ever
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
abstract class DnsInitCommand extends AbstractToolInitCommand
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithCloudflareApi,
        InteractsWithClusterContext, InteractsWithClusterIdentity, InteractsWithDnsZones,
        InteractsWithRoute53Api, LaraKubeOutput, PromotesIngressDns, ReadsStoredDnsCredentials,
        RequiresFlagsWhenNonInteractive, ResolvesToolEnvironment, StreamsProcessOutput;

    private const CLOUDFLARE_TOKEN_ENV = 'LARAKUBE_CLOUDFLARE_TOKEN';

    protected function runInit(): int
    {
        return $this->deployDns();
    }

    protected function deployDns(): int
    {
        $env = $this->resolveToolEnvironment(ClusterTool::DNS);

        if ($env === 'local') {
            $this->laraKubeError('ExternalDNS is only supported on cloud environments.');

            return 1;
        }

        $context = $this->resolveToolContext($env, (string) $this->option('context') ?: null);
        $kubectl = Kubectl::forContext($context)->prefix();
        $ns = 'larakube-shared';

        $provider = DnsProvider::from((string) ($this->option('provider') ?: DnsProvider::CLOUDFLARE->value));

        if ($provider === DnsProvider::ROUTE53 && ! $this->route53Available()) {
            $this->laraKubeError('The AWS CLI is required to manage Route53 zones. Run `larakube setup --tools=aws` first.');

            return 1;
        }

        $credential = $this->resolveCredential($provider, $kubectl, $ns);
        if ($credential === null) {
            return 1;
        }

        $zones = $this->resolveZones($provider, $credential);
        if ($zones === []) {
            return 1;
        }

        $group = $this->resolveGroup($zones, $env);
        if ($group === false) {
            return 1;
        }

        $groupSlug = $this->groupSlug($zones, $group);

        if (! $this->checkForConflicts($kubectl, $zones, $groupSlug)) {
            return 1;
        }

        $nsResult = null;
        $this->withSpin("Ensuring namespace {$ns}...", function () use ($kubectl, $ns, &$nsResult): void {
            $nsResult = Process::run("{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -");
        });

        if ($nsResult !== null && ! $nsResult->successful()) {
            if ($err = trim($nsResult->errorOutput() ?: $nsResult->output())) {
                $this->line("  <fg=red>{$err}</>");
            }
            $this->laraKubeError("Could not create/apply the '{$ns}' namespace.");

            return 1;
        }

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
            "ExternalDNS will manage {$zoneList} from '{$env}' via {$provider->label()}:",
            "Records are created and DELETED to match this cluster's ingresses.",
            "Only records owned by {$ownerId} are touched.",
        ])) {
            return 0;
        }

        $this->withSpin("Syncing the {$provider->label()} credential for {$groupSlug}...", fn () => Kubectl::fromPrefix($kubectl)->putSecret($ns, $provider->credentialSecretName($groupSlug), $credential));

        $manifest = view('k8s.dns.zone', [
            'namespace' => $ns,
            'zones' => $zones,
            'slug' => $groupSlug,
            'ownerId' => $ownerId,
            'provider' => $provider,
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
        $this->line('  <fg=gray>A zone with a different account or provider needs its own group:</>');
        $this->line("  <fg=blue>larakube tool:init --tool=external-dns {$env} --provider=… --cloudflare-token=…</>");
        $this->line('  <fg=gray>See everything this cluster manages:</> <fg=blue>larakube external-dns:list '.$env.'</>');
        $this->newLine();

        return 0;
    }

    /**
     * The credential driving discovery, as Secret data keyed by
     * $provider->credentialSecretKeys(). No zone is known yet at this point —
     * the credential's own scope IS what determines which zone(s) this
     * instance ends up managing (see resolveZones()).
     *
     * @return array<string, string>|null
     */
    protected function resolveCredential(DnsProvider $provider, string $kubectl, string $ns): ?array
    {
        return match ($provider) {
            DnsProvider::CLOUDFLARE => $this->resolveCloudflareCredential($kubectl, $ns),
            DnsProvider::ROUTE53 => $this->resolveRoute53Credential($kubectl, $ns),
        };
    }

    /**
     * @return array<string, string>|null
     */
    protected function resolveCloudflareCredential(string $kubectl, string $ns): ?array
    {
        $token = (string) ($this->option('cloudflare-token') ?? '');
        if ($token !== '') {
            return ['token' => $token];
        }

        // The same variable tls:init reads, so a caller can hand the token over
        // without it ever appearing in the process list.
        $fromEnv = trim((string) getenv(self::CLOUDFLARE_TOKEN_ENV));
        if ($fromEnv !== '') {
            return ['token' => $fromEnv];
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
        $stored = array_filter(
            $this->storedDnsCredentials($kubectl, $ns),
            fn (array $entry): bool => $entry['provider'] === DnsProvider::CLOUDFLARE,
        );

        if (count($stored) === 1) {
            $slug = array_key_first($stored);
            $this->laraKubeInfo("Reusing the stored Cloudflare token for '{$slug}'.");
            $this->line('  <fg=gray>Pass</> <fg=blue>--cloudflare-token=</> <fg=gray>to replace it.</>');

            return $stored[$slug]['data'];
        }

        if ($this->cannotPrompt()) {
            throw new MissingFlagException(
                'cloudflare-token',
                'the Cloudflare API token for the zone(s) to manage',
                'larakube tool:init --tool=external-dns production --cloudflare-token=…',
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

        return ['token' => (string) text(label: 'Cloudflare API token', required: true)];
    }

    /**
     * @return array<string, string>|null
     */
    protected function resolveRoute53Credential(string $kubectl, string $ns): ?array
    {
        $accessKeyId = (string) ($this->option('aws-access-key-id') ?: getenv('AWS_ACCESS_KEY_ID') ?: '');
        $secretAccessKey = (string) ($this->option('aws-secret-access-key') ?: getenv('AWS_SECRET_ACCESS_KEY') ?: '');

        if ($accessKeyId !== '' && $secretAccessKey !== '') {
            $region = (string) ($this->option('aws-region') ?: getenv('AWS_DEFAULT_REGION') ?: 'us-east-1');

            return ['access_key_id' => $accessKeyId, 'secret_access_key' => $secretAccessKey, 'region' => $region];
        }

        $stored = array_filter(
            $this->storedDnsCredentials($kubectl, $ns),
            fn (array $entry): bool => $entry['provider'] === DnsProvider::ROUTE53,
        );

        if (count($stored) === 1) {
            $slug = array_key_first($stored);
            $this->laraKubeInfo("Reusing the stored Route53 credential for '{$slug}'.");
            $this->line('  <fg=gray>Pass</> <fg=blue>--aws-access-key-id=</> <fg=gray>/</> <fg=blue>--aws-secret-access-key=</> <fg=gray>to replace it.</>');

            return $stored[$slug]['data'];
        }

        if ($this->cannotPrompt()) {
            throw new MissingFlagException(
                'aws-access-key-id',
                'the AWS access key ID and secret access key for Route53 (--aws-access-key-id= and --aws-secret-access-key=)',
                'larakube tool:init --tool=external-dns production --provider=route53 --aws-access-key-id=… --aws-secret-access-key=…',
            );
        }

        $this->newLine();
        foreach (DnsProvider::ROUTE53->onboardingSteps() as $step) {
            $this->line("  <fg=gray>{$step}</>");
        }
        $this->newLine();

        $keyId = (string) text(label: 'AWS Access Key ID', placeholder: 'AKIA...', required: true);
        $secret = (string) password(label: 'AWS Secret Access Key', required: true);
        $region = (string) text(label: 'AWS Region', default: 'us-east-1', required: true);

        $this->registerSecret($secret);

        return ['access_key_id' => $keyId, 'secret_access_key' => $secret, 'region' => $region];
    }

    /**
     * The zone(s) this instance will manage: every zone the credential can
     * see, narrowed to an explicit --zone= subset when given. Returns []
     * (having already printed its own error) on a bad credential, one with
     * no zone access, or a --zone= naming something it can't actually see —
     * never guesses or silently drops an unrecognised zone.
     *
     * @param  array<string, string>  $credential
     * @return list<string>
     */
    protected function resolveZones(DnsProvider $provider, array $credential): array
    {
        $discovered = array_values(match ($provider) {
            DnsProvider::CLOUDFLARE => $this->cloudflareListZones($credential['token']),
            DnsProvider::ROUTE53 => $this->route53ListZones($this->route53Env($credential)),
        });

        if ($discovered === []) {
            $this->laraKubeError("This credential has no zone access — check it's valid and scoped correctly for {$provider->label()}.");

            return [];
        }

        $requested = array_values(array_filter((array) ($this->option('zone') ?: [])));

        if ($requested === []) {
            return $discovered;
        }

        $missing = array_diff($requested, $discovered);
        if ($missing !== []) {
            $this->laraKubeError("This credential can't see: ".implode(', ', $missing));
            $this->line('  <fg=gray>Zones it can see: </>'.implode(', ', $discovered));

            return [];
        }

        return $requested;
    }

    /**
     * The stable --group= name for this instance. Never silently derived
     * from the zone set (see groupSlug()'s own docblock for why — it would
     * orphan the Deployment the moment a zone is added or removed from the
     * token's scope). Derived from the environment name instead when no
     * human is present to ask: that identifier is already unique per
     * cluster and zone-independent, so two different clusters sharing the
     * same multi-zone token can never collide — the exact incident a
     * shared/hardcoded owner ID caused before (see project history).
     * false signals "already errored, abort" — the same tri-state shape
     * resolveInstanceForTool() uses elsewhere in this codebase, kept
     * consistent rather than inventing a second convention for the same
     * kind of decision.
     *
     * @param  list<string>  $zones
     */
    protected function resolveGroup(array $zones, string $env): string|false|null
    {
        $group = (string) ($this->option('group') ?: '');
        if ($group !== '') {
            return $group;
        }

        if (count($zones) === 1) {
            return null;
        }

        if ($this->cannotPrompt()) {
            $auto = $this->zoneSlug($env);
            $this->laraKubeInfo(
                'This credential manages '.count($zones).' zones ('.implode(', ', $zones).') — no --group given, '
                ."using '{$auto}' (this cluster's own name) so it can't collide with another cluster sharing this credential.",
            );

            return $auto;
        }

        return (string) text(
            label: 'This credential manages '.count($zones).' zones ('.implode(', ', $zones).') — name this instance',
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
                        ."(external-dns:remove --zone={$zone}), or pass --group={$entry['slug']} here to fold it into that instance.",
                    );

                    return false;
                }
            }
        }

        return true;
    }
}
