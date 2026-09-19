<?php

namespace App\Commands\Sign;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Data\ResourceRef;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\SecretKind;

class SignRemoveCommand extends AbstractToolRemoveCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::SIGN;
    }

    protected function usesBundledStorage(string $kubectl, string $namespace): bool
    {
        return ! $this->cluster()->exists(new ResourceRef('Secret', $this->names($kubectl)->secret(), $namespace));
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        $names = $this->names($kubectl);
        $deployment = $names->deployment();

        return $this->deleteResources('Removing Documenso resources...', [
            new ResourceRef('Deployment', $deployment, $namespace),
            new ResourceRef('Service', $deployment, $namespace),
            new ResourceRef('Ingress', $deployment, $namespace),
            new ResourceRef('Secret', $names->secret(), $namespace),
            new ResourceRef('Secret', $names->secret(SecretKind::SMTP), $namespace),
            new ResourceRef('Secret', $names->secret(SecretKind::OIDC), $namespace),
            new ResourceRef('Secret', $names->name('signing-cert'), $namespace),
        ]);
    }

    private function names(string $kubectl): ToolInstance
    {
        return ToolInstance::forInstance(ClusterTool::SIGN, (string) $this->resolveInstance($kubectl));
    }
}
