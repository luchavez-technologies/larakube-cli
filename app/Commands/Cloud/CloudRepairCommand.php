<?php

namespace App\Commands\Cloud;

use App\Facades\State;
use App\Traits\EmitsJsonOutput;
use App\Traits\InteractsWithGlobalConfig;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ProvisionsK3sNode;
use App\Traits\RequiresFlagsWhenNonInteractive;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;

/**
 * Re-runs the exact same idempotent k3s provisioning pipeline cloud:create
 * and cloud:init already drive (ProvisionsK3sNode), against a server LaraKube
 * already manages instead of a brand-new one — for when a cluster is broken
 * (e.g. an undersized VPS that died installing something too heavy) but the
 * VM itself still answers over SSH. Re-hardens, reinstalls k3s, re-syncs the
 * kubeconfig, and redeploys Traefik if missing — every step already safe to
 * repeat, so this never destroys or reprovisions the underlying VM. Deliberately
 * a new, thin command rather than repurposing cloud:init: that command's own
 * prompts/UX assume a brand-new, not-yet-registered box, where this one targets
 * a box LaraKube already has in its registry.
 */
class CloudRepairCommand extends Command
{
    use EmitsJsonOutput, InteractsWithGlobalConfig, InteractsWithProjectConfig, LaraKubeOutput, ProvisionsK3sNode, RequiresFlagsWhenNonInteractive;

    protected $signature = 'cloud:repair
        {--stack= : The server to repair. Omit to pick from the registry.}
        {--admin-cidr= : Restrict SSH + the k3s API (6443) to this CIDR; omit to leave/keep open}
        {--force : Skip the confirmation prompt}
        {--json : Emit machine-readable JSON result}';

    protected $description = 'Re-run k3s provisioning on an already-registered server without destroying it';

    private array $result = [];

    public function handle(): int
    {
        if ($this->option('json') || $this->isAiAgent()) {
            $this->enableJsonMode();
        }

        $exit = $this->repair();

        if (State::isJsonMode()) {
            $this->jsonOutput($exit === 0
                ? array_merge(['success' => true, 'stackName' => null, 'context' => null], $this->result, ['error' => null])
                : ['success' => false, 'stackName' => $this->result['stackName'] ?? null, 'error' => State::lastError() ?? 'Repair did not complete.']);
        }

        return $exit;
    }

    private function repair(): int
    {
        $this->renderHeader();

        $vps = array_filter($this->getGlobalConfig()->getStacks(), fn ($stack): bool => $stack->kind === 'vps' && $stack->ip !== null);

        if ($vps === []) {
            $this->laraKubeWarn('No server to repair. (Only servers made with cloud:create/cloud:init can be repaired.)');

            return 1;
        }

        $name = $this->flagOrPrompt(
            'stack',
            fn () => select(
                label: 'Which server do you want to repair?',
                options: array_map(fn ($stack): string => "{$stack->name}  ({$stack->ip})", $vps),
            ),
            'the server to repair',
            'larakube cloud:repair --stack=my-server --no-interaction',
        );

        $stack = $vps[$name] ?? null;

        if ($stack === null) {
            $this->laraKubeError("No repairable server named '{$name}'. Only servers made with cloud:create/cloud:init can be repaired.");

            return 1;
        }

        $this->result['stackName'] = $stack->name;

        if ($stack->sshKey === null || ! is_file($stack->sshKey)) {
            $this->laraKubeError("The SSH key for '{$name}' is not on this machine, so it cannot be repaired from here.");

            return 1;
        }

        if (! $this->option('force') && ! confirm(
            "Repair '{$name}'? This re-applies hardening, k3s, kubeconfig sync, and Traefik — anything already fine is left alone, and the VM itself is never destroyed.",
            default: false,
        )) {
            $this->laraKubeInfo('Left as it is.');

            return 0;
        }

        $user = 'larakube';
        $port = '22';

        if (! $this->testSsh($user, $stack->ip, $port, $stack->sshKey)) {
            $message = "Cannot reach {$name} over SSH at {$stack->ip}, so it was not repaired.";
            $this->laraKubeError($message);
            State::setLastError($message);

            return 1;
        }

        $config = $this->getProjectConfigObject(getcwd());

        // Non-interactive regardless of --no-interaction: by the time someone
        // (or Desktop) asks to repair a server, they've already decided to do
        // every step, not be walked through a 5-question wizard for it.
        $context = $this->provisionK3sNode($user, $stack->ip, $port, $stack->sshKey, $config, interactive: false, adminCidr: $this->option('admin-cidr'));

        $this->result['context'] = $context;

        $this->newLine();
        $this->laraKubeInfo("✅ '{$name}' repaired — k3s reinstalled, kubeconfig re-synced, Traefik confirmed.");
        $this->newLine();

        return 0;
    }
}
