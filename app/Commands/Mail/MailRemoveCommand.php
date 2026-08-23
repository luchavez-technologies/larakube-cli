<?php

namespace App\Commands\Mail;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Enums\ClusterTool;
use App\Traits\InteractsWithMail;
use Illuminate\Support\Facades\Process;

class MailRemoveCommand extends AbstractToolRemoveCommand
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
        // Webmail's own secret is instance-suffixed now — best-effort lookup
        // of its current instance so this doesn't leave a real, live-named
        // credential Secret orphaned (an unsuffixed 'webmail-secrets' never
        // exists post-migration, so --ignore-not-found alone would silently
        // skip it).
        $webmailInstance = $this->getToolHost($kubectl, ClusterTool::WEBMAIL) !== null
            ? (string) ($this->getToolInstanceData($kubectl, ClusterTool::WEBMAIL)?->instance ?? '')
            : '';
        $webmailSecret = $webmailInstance !== '' ? "webmail-secrets-{$webmailInstance}" : 'webmail-secrets';

        // Mail's OWN resources are instance-suffixed too, same reasoning.
        $instance = (string) ($this->getToolInstanceData($kubectl, ClusterTool::MAIL)?->instance ?? '');
        $suffix = $instance !== '' ? "-{$instance}" : '';
        $deployment = "mail-stalwart{$suffix}";
        $mailSecrets = "mail-secrets{$suffix}";
        $configMap = "mail-stalwart-config{$suffix}";

        $ok = $this->removeResources(
            'Removing Stalwart resources...',
            "{$kubectl} delete deployment/{$deployment} service/{$deployment} service/mail-stalwart-mail{$suffix} "
            ."ingress/{$deployment} secret/{$mailSecrets} secret/mail-sender secret/mail-relay "
            ."secret/{$webmailSecret} configmap/{$configMap} -n {$namespace} --ignore-not-found",
        );

        // Wait for pods to fully terminate — PVCs can't be deleted while bound.
        // Stable label (mail-stalwart), not instance-suffixed — see
        // InteractsWithMail::isMailInstalled()'s same reasoning.
        Process::run("{$kubectl} wait --for=delete pod -l app=mail-stalwart -n {$namespace} --timeout=60s 2>/dev/null || true");

        // Standalone PVC — not garbage-collected with the Deployment. NOT
        // instance-suffixed — this is Stalwart's live mail data.
        $ok = $this->removeResources(
            'Removing Stalwart storage...',
            "{$kubectl} delete pvc/stalwart-data -n {$namespace} --ignore-not-found",
        ) && $ok;

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
