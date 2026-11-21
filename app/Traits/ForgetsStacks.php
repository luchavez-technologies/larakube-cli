<?php

namespace App\Traits;

use App\Data\StackData;

/**
 * Removing a stack from this machine: its registry entry, its OpenTofu state (local and in R2) and passphrase, its SSH
 * host key and its kube context. Shared by cloud:destroy (after the cloud resources are deleted) and
 * cloud:forget (which leaves them alone).
 *
 * Needs InteractsWithOpenTofu, InteractsWithRemoteSsh, LaraKubeOutput and ManagesDevBoxTunnel.
 */
trait ForgetsStacks
{
    protected function forgetStackEverywhere(StackData $stack): void
    {
        if ($stack->role === 'dev') {
            $this->stopTunnel($stack);
        }

        // Before removeStack(): it needs the remote-state settings, and its passphrase is about to go.
        if (($left = $this->removeRemoteTofuState($stack->name)) !== null) {
            $this->laraKubeWarn("Couldn't delete this server's saved state in R2. Delete {$left} in the Cloudflare dashboard before reusing the name '{$stack->name}'.");
        }

        $config = $this->getGlobalConfig();
        $config->removeStack($stack->name);
        $config->save();

        // removeStack() just cleared this stack's encryption passphrase. Its local state file is now
        // permanently undecryptable and would break a later cloud:create under the same name.
        $this->removeTofuWorkdir($stack->name);
        $this->forgetHostKey($stack->ip);

        // A dev box has a context only once it was connected.
        $context = $stack->context ?: ($stack->role === 'dev' || ! $stack->ip ? null : 'larakube-'.$stack->ip);
        if ($context) {
            $this->call('context:remove', ['name' => $context, '--force' => true]);
        }
    }
}
