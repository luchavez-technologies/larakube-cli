<?php

namespace App\Commands\Notes;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Enums\ClusterTool;

class NotesRemoveCommand extends AbstractToolRemoveCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::NOTES;
    }

    /**
     * Instance-scoped, matching exactly what notes:init names (see
     * NotesInitCommand::deployNotes()) — deployment/service/ingress/secrets
     * all suffix by instance except the SMTP secret, which mail:wire still
     * writes to a single fixed name (notes-outline-smtp) for every instance
     * (a pre-existing, separate gap in mail:wire's own instance-awareness).
     * Deleting it on a non-main instance's removal would break main's SMTP
     * wiring, so it's only torn down alongside main.
     */
    protected function teardown(string $kubectl, string $namespace): bool
    {
        $instance = $this->resolveInstance($kubectl);
        $isDefault = ($instance === null || $instance === '' || $instance === 'main');
        $deploymentName = ClusterTool::NOTES->deploymentName($instance);
        $serviceName = $isDefault ? 'notes' : "notes-{$instance}";
        $secretName = $isDefault ? 'notes-secrets' : "notes-secrets-{$instance}";
        $oidcSecretName = $isDefault ? 'notes-outline-oidc' : "notes-outline-oidc-{$instance}";

        $resources = "deployment/{$deploymentName} service/{$serviceName} ingress/{$serviceName} "
            ."secret/{$secretName} secret/{$oidcSecretName}";

        if ($isDefault) {
            $resources .= ' secret/notes-outline-smtp';
        }

        return $this->removeResources(
            'Removing Outline resources...',
            "{$kubectl} delete {$resources} -n {$namespace} --ignore-not-found",
        );
    }
}
