<?php

namespace App\Traits;

use App\Data\StackData;
use App\Services\Kubectl;
use App\Services\Workspace\WorkspaceSpec;
use InvalidArgumentException;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Picks the server a workspace command acts on: a server LaraKube made
 * (`--stack`) or any kube-context (`--context`, e.g. a local OrbStack cluster).
 * The using class needs InteractsWithGlobalConfig, LaraKubeOutput,
 * ReadsCommandOptions and RequiresFlagsWhenNonInteractive.
 */
trait TargetsWorkspaceServer
{
    /**
     * @return array{stack: ?StackData, context: string}|null null after printing why
     */
    protected function workspaceServer(): ?array
    {
        $stackName = $this->flag('stack');
        $context = $this->flag('context');

        if (! $stackName && ! $context) {
            $servers = array_filter($this->getGlobalConfig()->getStacks(), fn (StackData $stack): bool => $stack->kind === 'vps' && $stack->context !== null);

            $stackName = $this->flagOrPrompt(
                'stack',
                fn (): string => (string) select('Which server?', array_map(fn (StackData $stack): string => "{$stack->name}  ({$stack->ip})", $servers)),
                'the server to use (or --context for a local cluster)',
                'my-server',
            );
        }

        $stack = $stackName ? $this->getGlobalConfig()->findStack((string) $stackName) : null;

        if ($stackName && $stack === null) {
            $this->laraKubeError("No server named '{$stackName}'.");

            return null;
        }

        $context = (string) ($context ?: $stack?->context);

        if ($context === '') {
            $this->laraKubeError("'{$stackName}' has no kube-context yet. Is it finished provisioning?");

            return null;
        }

        return ['stack' => $stack, 'context' => $context];
    }

    protected function workspaceKubectl(string $context): Kubectl
    {
        return Kubectl::forContext($context);
    }

    protected function requireWorkspaceName(): string
    {
        $name = $this->flagOrPrompt('name', fn (): string => text('Workspace name', hint: 'lowercase letters, digits and dashes'), 'the workspace name', 'my-app');

        if (! WorkspaceSpec::validName($name)) {
            throw new InvalidArgumentException('A workspace name is lowercase letters, digits and dashes, up to 30 characters.');
        }

        return $name;
    }

    /**
     * The workspace's namespace, or null (after saying so) when none by that name exists.
     * Looking it up by label keeps remove from ever touching a namespace that isn't a workspace.
     */
    protected function findWorkspace(Kubectl $kubectl, string $name): ?array
    {
        $namespaces = $kubectl->raw(['get', 'namespace', '-l', 'larakube-workspace='.$name, '-o', 'json'])->json()['items'] ?? [];

        if ($namespaces === []) {
            $this->laraKubeError("No workspace named '{$name}' on that server.");

            return null;
        }

        return $namespaces[0];
    }
}
