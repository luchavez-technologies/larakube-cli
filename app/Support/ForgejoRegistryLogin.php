<?php

namespace App\Support;

use App\Data\GlobalConfigData;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Services\Kubectl;

/**
 * Finds the registry login `tool:init --tool=forgejo` minted for a Forgejo host. Forgejo often runs
 * on a different server than the environment that pulls from it, so the environment's own cluster is
 * checked first, then every other server LaraKube manages.
 */
final class ForgejoRegistryLogin
{
    /**
     * @param  callable(string $kubectl, string $namespace, string $secret, string $key): ?string  $readKey
     * @return array{username: string, token: string, context: string}|null
     */
    public static function find(string $registryHost, ?string $firstContext, callable $readKey): ?array
    {
        // An empty first context means the current one, so only null is skipped there.
        $others = array_filter(array_map(fn ($stack): ?string => $stack->context, GlobalConfigData::load()->getStacks()));
        $contexts = array_values(array_unique([...($firstContext === null ? [] : [$firstContext]), ...$others]));
        $namespace = ClusterTool::FORGEJO->namespace();
        $secret = ToolInstance::forHost(ClusterTool::FORGEJO, $registryHost)->secret();

        foreach ($contexts as $context) {
            $kubectl = Kubectl::forContext($context)->prefix();
            $username = trim((string) $readKey($kubectl, $namespace, $secret, 'username'));
            $token = trim((string) $readKey($kubectl, $namespace, $secret, 'registry-token'));

            if ($username !== '' && $token !== '' && $token !== 'pending') {
                return ['username' => $username, 'token' => $token, 'context' => $context];
            }
        }

        return null;
    }
}
