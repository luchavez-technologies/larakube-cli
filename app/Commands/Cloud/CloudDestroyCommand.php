<?php

namespace App\Commands\Cloud;

use App\Facades\State;
use App\Traits\DiscoversUnfinishedStacks;
use App\Traits\InteractsWithOpenTofu;
use App\Traits\InteractsWithRemoteSsh;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Facades\Process;

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
    use DiscoversUnfinishedStacks, InteractsWithOpenTofu, InteractsWithRemoteSsh, LaraKubeOutput;

    protected $signature = 'cloud:destroy
        {stack? : The stack name to destroy. Omit to pick from the registry.}
        {--force : Skip the confirmation prompt}
        {--hetzner-token= : Hetzner Cloud API token}
        {--aws-profile= : AWS CLI profile name}
        {--gcp-account= : Google Cloud account email}
        {--gcp-project= : Google Cloud project ID}';

    protected $description = 'Destroy an OpenTofu-provisioned stack (droplet or DOKS cluster) and remove it from the registry';

    public function handle(): int
    {
        $this->renderHeader();

        $stacks = $this->getGlobalConfig()->getStacks();
        $unfinished = $this->getUnfinishedStacks();

        if (empty($stacks) && empty($unfinished)) {
            $this->laraKubeWarn('No stacks or unfinished setups found. (Nothing was created via cloud:create on this machine.)');

            return 0;
        }

        $allOptions = [];
        foreach ($stacks as $k => $s) {
            $allOptions[$k] = $s->name.'  ('.$s->kind.', '.($s->region ?? '?').($s->ip ? ', '.$s->ip : '').')';
        }
        foreach ($unfinished as $k => $s) {
            $allOptions[$k] = $s->name.'  ('.$s->kind.' - unfinished setup in ~/.larakube/tofu'.($s->ip ? ', '.$s->ip : '').')';
        }

        $name = $this->argument('stack') ?: select(
            label: 'Which stack do you want to DESTROY?',
            options: $allOptions,
        );

        $stack = $this->getGlobalConfig()->findStack($name) ?? ($unfinished[$name] ?? null);
        if (! $stack) {
            $dir = home_path('.larakube/tofu/'.$name);
            if (is_dir($dir)) {
                $stack = $this->synthesizeUnfinishedStack($name, $dir);
            }
        }

        if (! $stack) {
            $this->laraKubeError("No registered stack or unfinished setup named '{$name}'.");

            return 1;
        }

        if ($flagHetzner = $this->option('hetzner-token')) {
            State::setTransientHetznerToken($flagHetzner);
        }

        if ($flagProfile = $this->option('aws-profile')) {
            State::setTransientAwsProfile($flagProfile);
        } elseif ($stack->provider === 'aws' && $stack->account) {
            State::setTransientAwsProfile($stack->account);
        }

        if ($flagAccount = $this->option('gcp-account')) {
            State::setTransientGcpAccount($flagAccount);
            Process::run('gcloud config set account '.escapeshellarg(State::transientGcpAccount()).' 2>/dev/null');
        } elseif ($stack->provider === 'gcp' && $stack->account) {
            State::setTransientGcpAccount($stack->account);
            Process::run('gcloud config set account '.escapeshellarg($stack->account).' 2>/dev/null');
        }

        if ($flagProject = $this->option('gcp-project')) {
            State::setTransientGcpProject($flagProject);
        } elseif ($stack->provider === 'gcp' && $stack->projectId) {
            State::setTransientGcpProject($stack->projectId);
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
        $this->forgetHostKey($stack->ip);
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
