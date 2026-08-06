<?php

namespace App\Traits;

use App\Enums\ClusterTool;
use App\Exceptions\MissingFlagException;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

use function Laravel\Prompts\select;

/**
 * Helpers for the Meet tool (shared LiveKit SFU) and its consumer key registry.
 *
 * LiveKit's `keys:` block is a map of apiKey => apiSecret, and it accepts any
 * number of pairs. Every consumer — Synapse via lk-jwt, each Laravel project —
 * gets its own pair so one can be rotated or revoked without touching the
 * others. The `meet-keys` Secret is the source of truth; livekit.yaml is
 * rendered from it, never hand-edited.
 *
 * Note the isolation boundary this does NOT give you: OSS LiveKit has no
 * per-key room restriction, so any valid key can mint a token for any room.
 * Scoping is by the roomPrefix convention each consumer is issued. See
 * docs/decisions/0009-shared-livekit-and-per-consumer-keys.md.
 */
trait InteractsWithMeet
{
    use ReadsClusterSecrets;

    /**
     * The always-present bootstrap consumer. livekit-server refuses to start at
     * all on an empty `keys:` map ("one of key-file or keys must be provided"),
     * so a registry with no consumers — a fresh `meet:init`, or the last
     * `meet:unwire` — would CrashLoopBackOff the SFU. Reserved name; a project
     * or tool can never be called this because consumers are slugs.
     */
    protected const MEET_SYSTEM_CONSUMER = '_system';

    /** The namespace the meet stack lives in. */
    protected function meetNamespace(): string
    {
        return 'larakube-shared';
    }

    /** Build the kubectl command, optionally scoped to a context, pinned to ~/.kube/config. */
    protected function meetKubectl(?string $context = null): string
    {
        $context = (string) ($context ?? '');
        $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

        return $context !== '' ? "{$kubectl} --context={$context}" : $kubectl;
    }

    /** Is the shared LiveKit deployed? */
    protected function isMeetInstalled(string $kubectl, string $ns): bool
    {
        return trim(Process::run("{$kubectl} get deployment meet-livekit -n {$ns} --no-headers --ignore-not-found")->output()) !== '';
    }

    /**
     * The whole consumer registry, keyed by consumer slug ('chat', or a project
     * name). Missing/!unparseable Secret reads as empty so a first `meet:init`
     * on a clean cluster is not a special case.
     *
     * @return array<string, array{key: string, secret: string, roomPrefix: string, webhookUrl: ?string}>
     */
    protected function readMeetKeys(string $kubectl, string $ns): array
    {
        $raw = $this->readClusterSecretKey($kubectl, $ns, 'meet-keys', 'consumers.json');

        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Add or refresh a consumer, returning the full registry to render from.
     * An existing consumer keeps its key pair — re-running a wire must not
     * invalidate credentials an app already has in its .env.
     *
     * @param  array<string, array{key: string, secret: string, roomPrefix: string, webhookUrl: ?string}>  $registry
     * @return array<string, array{key: string, secret: string, roomPrefix: string, webhookUrl: ?string}>
     */
    protected function allocateMeetKey(array $registry, string $consumer, string $roomPrefix, ?string $webhookUrl = null): array
    {
        $existing = $registry[$consumer] ?? null;

        $registry[$consumer] = [
            'key' => $existing['key'] ?? 'LK_'.Str::random(16),
            'secret' => $existing['secret'] ?? Str::random(32),
            'roomPrefix' => $roomPrefix,
            'webhookUrl' => $webhookUrl ?? ($existing['webhookUrl'] ?? null),
        ];

        return $registry;
    }

    /**
     * Drop a consumer. Returns the registry unchanged when the consumer was
     * never registered, so unwire is idempotent.
     *
     * @param  array<string, array{key: string, secret: string, roomPrefix: string, webhookUrl: ?string}>  $registry
     * @return array<string, array{key: string, secret: string, roomPrefix: string, webhookUrl: ?string}>
     */
    protected function revokeMeetKey(array $registry, string $consumer): array
    {
        unset($registry[$consumer]);

        return $registry;
    }

    /**
     * Persist the registry. Sorted so an unchanged registry always serializes
     * byte-identically — the config-checksum that forces a LiveKit rollout is
     * derived from this string, and unstable ordering would restart the SFU
     * (dropping every live call) on every unrelated `meet:init`.
     *
     * The system key is seeded here rather than at any call site so no caller
     * can persist a registry that would refuse to boot.
     *
     * @param  array<string, array{key: string, secret: string, roomPrefix: string, webhookUrl: ?string}>  $registry
     * @return array<string, array{key: string, secret: string, roomPrefix: string, webhookUrl: ?string}> the persisted registry, including the seeded system key
     */
    protected function writeMeetKeys(string $kubectl, string $ns, array $registry): array
    {
        if (! isset($registry[self::MEET_SYSTEM_CONSUMER])) {
            $registry = $this->allocateMeetKey($registry, self::MEET_SYSTEM_CONSUMER, 'system-');
        }

        ksort($registry);
        $json = (string) json_encode($registry, JSON_UNESCAPED_SLASHES);

        Process::run(
            "{$kubectl} create secret generic meet-keys -n {$ns} "
            .'--from-literal=consumers.json='.escapeshellarg($json).' '
            ."--dry-run=client -o yaml | {$kubectl} apply -f -",
        );

        return $registry;
    }

    /**
     * Resolve which tool to wire. Mirrors MailWireCommand::resolveTargets():
     * honour --tool= when given, prompt from the installed set when not, and
     * fail with the flag name rather than hanging when there is no TTY (CI,
     * MCP, the `larakube` proxy). Deliberately no default on the flag — a
     * silent assumption is wrong the moment a second tool becomes wireable.
     */
    protected function resolveMeetWireTarget(string $kubectl, string $verb): ?ClusterTool
    {
        $installed = array_values(array_filter(
            ClusterTool::cases(),
            fn (ClusterTool $t) => $t->hasMeetWire()
                && trim(Process::run(
                    "{$kubectl} get deployment {$t->deploymentName()} -n {$t->namespace()} --no-headers --ignore-not-found",
                )->output()) !== '',
        ));

        $slug = $this->option('tool');

        if ($slug !== null && $slug !== '') {
            $tool = ClusterTool::tryFrom($slug);

            if ($tool === null || ! $tool->hasMeetWire()) {
                $this->laraKubeError("'{$slug}' cannot be wired to Meet. Laravel apps use `larakube add meet` instead.");

                return null;
            }

            if (! in_array($tool, $installed, true)) {
                $this->laraKubeError("{$tool->getLabel()} is not installed on this cluster.");

                return null;
            }

            return $tool;
        }

        if ($installed === []) {
            $this->laraKubeError('No Meet-capable tools are installed on this cluster.');

            return null;
        }

        $options = [];
        foreach ($installed as $tool) {
            $options[$tool->value] = $tool->getLabel();
        }

        if ($this->cannotPrompt()) {
            throw new MissingFlagException('tool', "which tool to {$verb}", "larakube meet:{$verb} production --tool=…");
        }

        return ClusterTool::from(select(
            label: "Which tool would you like to {$verb} ".($verb === 'wire' ? 'to' : 'from').' Meet?',
            options: $options,
            scroll: count($options),
        ));
    }

    /**
     * The registry minus the bootstrap key — what a human means by "consumers".
     *
     * @param  array<string, array{key: string, secret: string, roomPrefix: string, webhookUrl: ?string}>  $registry
     * @return array<string, array{key: string, secret: string, roomPrefix: string, webhookUrl: ?string}>
     */
    protected function meetConsumers(array $registry): array
    {
        unset($registry[self::MEET_SYSTEM_CONSUMER]);

        return $registry;
    }
}
