<?php

namespace App\Commands\Cloud;

use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

/**
 * List the globally registered stacks — provisioned droplets and managed clusters
 * that `cloud:create` created or `cloud:destroy` has not yet removed.
 */
class CloudStacksCommand extends Command
{
    use LaraKubeOutput;

    protected $signature = 'cloud:stacks';

    protected $description = 'List all registered infrastructure stacks (VPS + managed clusters)';

    public function handle(): int
    {
        $stacks = $this->getGlobalConfig()->getStacks();

        if (empty($stacks)) {
            $this->laraKubeInfo('No stacks registered. (Nothing created via cloud:create on this machine.)');

            return 0;
        }

        $rows = [];
        foreach ($stacks as $stack) {
            $rows[] = [
                $stack->name,
                $stack->kind,
                $stack->region ?? '—',
                $stack->ip ?? '—',
                $stack->context ?? '—',
                $stack->bindings === [] ? '—' : implode("\n", $stack->bindings),
            ];
        }

        table(
            headers: ['Name', 'Kind', 'Region', 'IP', 'Context', 'Bindings'],
            rows: $rows,
        );

        $this->newLine();
        $this->line('  <fg=gray>Tofu state:</> '.(count($stacks) === 1 ? $this->stateDir(array_key_first($stacks)) : '~/.larakube/tofu/<stack>/'));

        return 0;
    }

    private function stateDir(string $stack): string
    {
        return home_path('.larakube/tofu/'.$stack);
    }
}
