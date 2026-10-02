<?php

namespace App\Commands\Mail;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Traits\InteractsWithMail;
use Illuminate\Support\Facades\Process;

abstract class MailRemoveCommand extends AbstractToolRemoveCommand
{
    use InteractsWithMail;

    protected function tool(): ClusterTool
    {
        return ClusterTool::MAIL;
    }

    protected function teardownWarning(string $env): array
    {
        return [
            "The Stalwart mail server will be REMOVED from '{$env}':",
            'Deployment, Services, Ingress, Secrets, ConfigMap, PVCs',
            'Mail-wire SMTP secrets for every wired tool',
            'Firewall ports (DO cloud + host UFW)',
            'All mailboxes and their stored messages. This cannot be undone.',
        ];
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        // Webmail's own credentials Secret is named per instance — a
        // best-effort lookup of its current one, so removing Stalwart doesn't
        // leave a real, live-named Secret orphaned behind it.
        $webmailInstance = $this->getToolHost($kubectl, ClusterTool::WEBMAIL) !== null
            ? (string) ($this->getToolInstanceData($kubectl, ClusterTool::WEBMAIL)?->instance ?? '')
            : '';
        $webmailSecret = $webmailInstance !== ''
            ? ToolInstance::forInstance(ClusterTool::WEBMAIL, $webmailInstance)->secret()
            : 'webmail-secrets';

        // Every resource Stalwart owns comes from the vendor's component list,
        // so this can't drift from what the manifest deploys; the volume is in
        // it, and Kubernetes holds the claim until its pod is gone.
        $instance = (string) ($this->getToolInstanceData($kubectl, ClusterTool::MAIL)?->instance ?? '');

        $ok = $this->removeResources(
            'Removing Stalwart resources...',
            $this->teardownComponentsCommand($kubectl, $namespace, $instance),
        );

        $ok = $this->removeResources(
            'Removing the webmail credentials...',
            "{$kubectl} delete secret/{$webmailSecret} -n {$namespace} --ignore-not-found",
        ) && $ok;

        // Wait for the pod to fully terminate before reporting done.
        Process::run("{$kubectl} wait --for=delete pod -l larakube.io/tool=mail -n {$namespace} --timeout=60s 2>/dev/null || true");

        // Mail-wire SMTP secrets (<tool>-smtp) — useless without Stalwart, and
        // they'd silently point a re-installed tool at a mail server that's gone.
        $wired = trim((string) Process::run([
            'bash', '-c',
            "{$kubectl} get secrets -n {$namespace} -o name --no-headers 2>/dev/null | grep '\\-smtp$' || true",
        ])->output());

        if ($wired !== '') {
            $ok = $this->removeResources(
                'Removing mail-wire SMTP secrets...',
                "{$kubectl} delete ".str_replace("\n", ' ', $wired)." -n {$namespace} --ignore-not-found",
            ) && $ok;
        }

        // Reverse the firewall openings (dedicated DO firewall + UFW rules).
        $this->closeMailPorts((string) $this->argument('environment'));

        return $ok;
    }
}
