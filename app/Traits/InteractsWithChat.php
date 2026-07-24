<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\SharedClusterService;
use Illuminate\Support\Facades\Process;

/**
 * Helpers for the Mattermost team-chat tool. Mirrors InteractsWithDesk — a
 * Commons-backed (Postgres + optional S3) shared stack in larakube-shared.
 */
trait InteractsWithChat
{
    use ResolvesEnvironmentContext;

    /** The namespace the chat stack lives in. */
    protected function chatNamespace(): string
    {
        return 'larakube-shared';
    }

    /** Build the kubectl command, optionally scoped to a context, pinned to ~/.kube/config. */
    protected function chatKubectl(?string $context = null): string
    {
        $context = (string) ($context ?? '');
        $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

        return $context !== '' ? "{$kubectl} --context={$context}" : $kubectl;
    }

    /** Mattermost Deployment present? */
    protected function isChatInstalled(string $kubectl, string $ns): bool
    {
        $out = Process::run("{$kubectl} get deployment chat-mattermost -n {$ns} --no-headers --ignore-not-found")->output();

        return trim($out) !== '';
    }

    /** Read a key from the chat-secrets secret. */
    protected function readChatSecret(string $kubectl, string $ns, string $key): ?string
    {
        $out = trim(Process::run(
            "{$kubectl} get secret chat-secrets -n {$ns} -o jsonpath='{.data.{$key}}'",
        )->output());

        return $out !== '' ? (string) base64_decode($out) : null;
    }

    /** Read-only Mattermost host for the given environment. */
    protected function resolveChatHostReadOnly(string $env, ?ConfigData $config): ?string
    {
        $service = SharedClusterService::CHAT;

        if ($env === 'local') {
            return $service->hostFor(GlobalConfigData::load()->getLocalTld());
        }

        return $config?->getEnvironment($env)?->hosts[$service->value] ?? null;
    }

    /** Resolve Mattermost's access details for status output. */
    protected function chatAccess(string $env, ?ConfigData $config, ?string $context = null): ?array
    {
        $kubectl = $this->chatKubectl($context);
        $ns = $this->chatNamespace();

        if (! $this->isChatInstalled($kubectl, $ns)) {
            return null;
        }

        return [
            'host' => $this->resolveChatHostReadOnly($env, $config),
            'label' => 'Mattermost',
        ];
    }
}
