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
        {--keep-data : Leave the database and storage in place}
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

        if (! $this->option('keep-data')) {
            $lines[] = 'Plex Commons database: chat_matrix';
        }

        return $lines;
    }

    /** A bundled (--no-plex) install runs its own Postgres Deployment. */
    protected function usesBundledStorage(string $kubectl, string $namespace): bool
    {
        return $this->deploymentExists($kubectl, $namespace, 'chat-synapse-db');
    }

    protected function dropCommonsTenants(string $kubectl): bool
    {
        $database = 'chat_matrix';

        if ($this->usesBundledStorage($kubectl, $this->tool()->namespace())) {
            return true;
        }

        $plexNs = $this->plexNamespace();
        $client = \App\Enums\DatabaseDriver::POSTGRESQL->commonsAdminClient();

        $sql = $this->buildDropTenantSql($database, $database);
        $tmp = tempnam(sys_get_temp_dir(), 'larakube_plex_drop_chat');
        file_put_contents($tmp, $sql);

        $ok = $this->removeResources(
            "Dropping database '{$database}' from Plex Commons (if exists)...",
            "{$kubectl} exec -i -n {$plexNs} deploy/postgres -- sh -c "
            .escapeshellarg($client).' < '.escapeshellarg($tmp),
        );

        $this->unregisterTenant($database);
        @unlink($tmp);

        return $ok;
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
