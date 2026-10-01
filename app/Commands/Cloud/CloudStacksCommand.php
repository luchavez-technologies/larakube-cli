<?php

namespace App\Commands\Cloud;

use App\Data\StackData;
use App\Traits\DiscoversUnfinishedStacks;
use App\Traits\EmitsJsonOutput;
use App\Traits\LaraKubeOutput;
use App\Traits\ManagesSshKeys;
use App\Traits\ReadsCommandOptions;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

/**
 * List the globally registered stacks — provisioned droplets and managed clusters
 * that `cloud:create` created or `cloud:destroy` has not yet removed.
 */
class CloudStacksCommand extends Command
{
    use DiscoversUnfinishedStacks, EmitsJsonOutput, LaraKubeOutput, ManagesSshKeys, ReadsCommandOptions;

    protected $signature = 'cloud:stacks
        {--json : Emit one machine-readable JSON result on stdout, including unfinished setups}';

    protected $description = 'List all registered infrastructure stacks (VPS + managed clusters)';

    public function handle(): int
    {
        $stacks = $this->getGlobalConfig()->getStacks();

        if ($this->flag('json')) {
            $this->jsonOutput(['success' => true, 'stacks' => $this->describeStacks($stacks, $this->getUnfinishedStacks())]);

            return 0;
        }

        if (empty($stacks)) {
            $this->laraKubeInfo('No stacks registered. (Nothing created via cloud:create on this machine.)');

            return 0;
        }

        $rows = [];
        foreach ($stacks as $stack) {
            $account = $stack->account ?? '—';
            if ($stack->projectId && $stack->account) {
                $account .= " ({$stack->projectId})";
            } elseif ($stack->projectId) {
                $account = $stack->projectId;
            }

            $rows[] = [
                $stack->name,
                $stack->provider ? strtoupper($stack->provider) : 'DO',
                $stack->kind,
                $stack->region ?? '—',
                $stack->ip ?? '—',
                $stack->context ?? '—',
                $account,
                $stack->bindings === [] ? '—' : implode("\n", $stack->bindings),
            ];
        }

        table(
            headers: ['Name', 'Provider', 'Kind', 'Region', 'IP', 'Context', 'Account / Project', 'Bindings'],
            rows: $rows,
        );

        $this->newLine();
        $this->line('  <fg=gray>Tofu state:</> '.(count($stacks) === 1 ? $this->stateDir(array_key_first($stacks)) : '~/.larakube/tofu/<stack>/'));

        return 0;
    }

    /**
     * `ready` once provisioning recorded a kube-context, `incomplete` when it
     * registered but never got that far, `unfinished` for a Tofu workdir no
     * registered stack owns (an interrupted cloud:create).
     *
     * @param  array<string, StackData>  $registered
     * @param  array<string, StackData>  $unfinished
     * @return list<array<string, mixed>>
     */
    private function describeStacks(array $registered, array $unfinished): array
    {
        $describe = fn (StackData $stack, string $status): array => [
            'name' => $stack->name,
            'provider' => $stack->provider,
            'kind' => $stack->kind,
            'region' => $stack->region,
            'ip' => $stack->ip,
            'context' => $stack->context,
            'sshKey' => $stack->sshKey ?? ($stack->ip ? $this->resolveSshDetails($stack->ip, $stack->context)['key'] ?? null : null),
            'account' => $stack->account,
            'projectId' => $stack->projectId,
            'bindings' => $stack->bindings,
            'createdAt' => $stack->createdAt,
            'status' => $status,
        ];

        $rows = [];
        foreach ($registered as $stack) {
            $rows[] = $describe($stack, $stack->context ? 'ready' : 'incomplete');
        }
        foreach ($unfinished as $stack) {
            $rows[] = $describe($stack, 'unfinished');
        }

        return $rows;
    }

    private function stateDir(string $stack): string
    {
        return home_path('.larakube/tofu/'.$stack);
    }
}
