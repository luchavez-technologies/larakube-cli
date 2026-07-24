<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\SharedClusterService;
use Illuminate\Support\Facades\Process;

trait InteractsWithNotes
{
    use ResolvesEnvironmentContext;

    protected function notesNamespace(): string
    {
        return 'larakube-shared';
    }

    protected function notesKubectl(?string $context = null): string
    {
        $context = (string) ($context ?? '');
        $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

        return $context !== '' ? "{$kubectl} --context={$context}" : $kubectl;
    }

    protected function isNotesInstalled(string $kubectl, string $ns): bool
    {
        $out = Process::run("{$kubectl} get deployment notes-outline -n {$ns} --no-headers --ignore-not-found")->output();

        return trim($out) !== '';
    }

    protected function readNotesSecret(string $kubectl, string $ns, string $key): ?string
    {
        $out = trim(Process::run(
            "{$kubectl} get secret notes-secrets -n {$ns} -o jsonpath='{.data.{$key}}'",
        )->output());

        return $out !== '' ? (string) base64_decode($out) : null;
    }

    protected function resolveNotesHostReadOnly(string $env, ?ConfigData $config): ?string
    {
        $service = SharedClusterService::NOTES;

        if ($env === 'local') {
            return $service->hostFor(GlobalConfigData::load()->getLocalTld());
        }

        return $config?->getEnvironment($env)?->hosts[$service->value] ?? null;
    }

    protected function notesAccess(string $env, ?ConfigData $config, ?string $context = null): ?array
    {
        $kubectl = $this->notesKubectl($context);
        $ns = $this->notesNamespace();

        if (! $this->isNotesInstalled($kubectl, $ns)) {
            return null;
        }

        return [
            'host' => $this->resolveNotesHostReadOnly($env, $config),
            'label' => 'Outline',
        ];
    }
}
