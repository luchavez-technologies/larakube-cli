<?php

namespace App\Commands\Cloud;

use App\Data\StackData;
use App\Traits\InteractsWithOpenTofu;
use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;

/**
 * Tear down an OpenTofu-provisioned stack (droplet or DOKS cluster) and forget it
 * from the global registry. The counterpart to `cloud:create`. Distinct from
 * `cloud:nuke`, which wipes an app's resources but leaves the infra running.
 */
class CloudDestroyCommand extends Command
{
    use InteractsWithOpenTofu, LaraKubeOutput;

    protected $signature = 'cloud:destroy
        {stack? : The stack name to destroy. Omit to pick from the registry.}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Destroy an OpenTofu-provisioned stack (droplet or DOKS cluster) and remove it from the registry';

    public function handle(): int
    {
        $this->renderHeader();

        $stacks = $this->getGlobalConfig()->getStacks();
        if (empty($stacks)) {
            $this->laraKubeWarn('No stacks are registered. (Nothing was created via cloud:create on this machine.)');

            return 0;
        }

        $name = $this->argument('stack') ?: select(
            label: 'Which stack do you want to DESTROY?',
            options: collect($stacks)->mapWithKeys(fn (StackData $s, $k) => [
                $k => $s->name.'  ('.$s->kind.', '.($s->region ?? '?').($s->ip ? ', '.$s->ip : '').')',
            ])->all(),
        );

        $stack = $this->getGlobalConfig()->findStack($name);
        if (! $stack) {
            $this->laraKubeError("No registered stack named '{$name}'.");

            return 1;
        }

        $bin = $this->ensureTofu();
        if (! $bin) {
            return 1;
        }

        $this->laraKubeInfo("Cloud Destroy: '{$stack->name}' ({$stack->kind}".($stack->ip ? ', '.$stack->ip : '').')');
        $this->warn('⚠ WARNING: This permanently deletes the droplet/cluster and ALL data on it.');

        // Surface still-bound environments so we don't yank infra out from under a
        // live deploy. Best-effort — only bindings recorded on this machine appear.
        if (! empty($stack->bindings)) {
            $this->laraKubeWarn('These environments are still bound to this stack:');
            foreach ($stack->bindings as $b) {
                $this->line("    <fg=yellow>{$b}</>");
            }
        }
        $this->newLine();

        if (! $this->option('force') && ! confirm("Type-confirm: really destroy '{$stack->name}'? This cannot be undone.", false)) {
            $this->laraKubeInfo('Destroy cancelled.');

            return 0;
        }

        if (! $this->tofuStateExists($stack->name)) {
            $this->laraKubeWarn('No Tofu state found for this stack — removing the registry entry only.');
            $this->forgetStack($stack->name);

            return 0;
        }

        $this->laraKubeInfo('Running tofu destroy...');
        if (! $this->tofuDestroy($bin, $stack->name)) {
            $this->laraKubeError('tofu destroy failed — leaving the registry entry so you can retry.');

            return 1;
        }

        $this->forgetStack($stack->name);
        $this->laraKubeInfo("✅ Destroyed and unregistered '{$stack->name}'.");

        // Offer to drop the local kube-context too (the cluster is gone now).
        if ($stack->context && ! $this->option('force')
            && confirm("Also remove the local kube-context '{$stack->context}'?", true)) {
            $this->call('context:remove', ['name' => $stack->context, '--force' => true]);
        }

        return 0;
    }

    private function forgetStack(string $name): void
    {
        $config = $this->getGlobalConfig();
        $config->removeStack($name);
        $config->save();

        // removeStack() just cleared this stack's encryption passphrase — leaving
        // its (now permanently undecryptable) local state file behind would break
        // `cloud:create` under the same stack name later with a "cipher: message
        // authentication failed" error, since a fresh passphrase can't decrypt
        // ciphertext it never encrypted.
        $this->removeTofuWorkdir($name);
    }
}
