<?php

namespace App\Commands\Notes;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\SecretKind;

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
        $isDefault = ($instance === null || $instance === '');
        // Per instance the names come from ToolInstance, so teardown deletes
        // exactly what init wrote (Service and Ingress share the Deployment's
        // name). The instance-less form is the pre-instance shape older
        // clusters still carry, and keeps the names they were given.
        $names = $isDefault ? null : ToolInstance::forInstance(ClusterTool::NOTES, $instance);
        $deploymentName = $names?->deployment() ?? 'notes-outline';
        $serviceName = $names?->deployment() ?? 'notes';
        $secretName = $names?->secret() ?? 'notes-secrets';
        $oidcSecretName = $names?->secret(SecretKind::OIDC) ?? 'notes-outline-oidc';

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
