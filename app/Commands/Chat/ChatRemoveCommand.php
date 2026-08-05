<?php

namespace App\Commands\Chat;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use App\Traits\ManagesToolFirewallPorts;

class ChatRemoveCommand extends AbstractToolRemoveCommand
{
    use ManagesToolFirewallPorts;

    public function __construct()
    {
        $this->signature = 'chat:remove
        {environment=local : Environment to remove Team Chat from}
        {--context=  : Target a specific kube-context}
        {--instance=main : Named instance identifier (default: main)}
        {--purge     : Also destroy persistent data — drop the Plex Commons database. Irreversible.}
        {--force     : Skip the confirmation prompt}';

        parent::__construct();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::CHAT;
    }

    protected function teardownWarning(string $env): array
    {
        $lines = [
            "Team Chat (Matrix) will be REMOVED from '{$env}':",
            "Deployments, Services, Ingresses and Secrets in {$this->tool()->namespace()}",
        ];

        if ($this->option('purge')) {
            $lines[] = 'Plex Commons database WILL BE DESTROYED: chat_matrix';
        } else {
            $lines[] = 'Persistent data (Plex Commons DB + S3 buckets) WILL BE PRESERVED.';
        }

        return $lines;
    }

    /** A bundled (--no-plex) install runs its own Postgres Deployment. */
    protected function usesBundledStorage(string $kubectl, string $namespace): bool
    {
        return $this->deploymentExists($kubectl, $namespace, 'chat-synapse-db');
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        $ok = $this->removeResources(
            'Removing Matrix (Synapse + Element) resources...',
            "{$kubectl} delete deployment/chat-synapse deployment/chat-cinny deployment/chat-synapse-db "
            .'service/chat-synapse service/chat-cinny service/chat-synapse-db '
            .'ingress/chat-ingress configmap/chat-synapse-config '
            .'pvc/chat-synapse-data pvc/chat-synapse-db-storage '
            ."secret/chat-secrets secret/chat-smtp secret/chat-oidc -n {$namespace} --ignore-not-found",
        );

        // Reverse chat:init's port opening — Coturn/LiveKit are gone but their
        // UDP/TCP ports being left open on the cloud firewall is real exposure
        // with nothing behind it.
        $this->closeToolPorts(SharedClusterService::CHAT, (string) $this->argument('environment'));

        return $ok;
    }
}
