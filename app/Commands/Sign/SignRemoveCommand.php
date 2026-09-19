<?php

namespace App\Commands\Sign;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\SecretKind;
use Illuminate\Support\Facades\Process;

class SignRemoveCommand extends AbstractToolRemoveCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::SIGN;
    }

    protected function usesBundledStorage(string $kubectl, string $namespace): bool
    {
        return trim(Process::run(
            "{$kubectl} get secret {$this->names($kubectl)->secret()} -n {$namespace} --ignore-not-found",
        )->output()) === '';
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        $names = $this->names($kubectl);
        $deployment = $names->deployment();

        return $this->removeResources(
            'Removing Documenso resources...',
            "{$kubectl} delete deployment/{$deployment} service/{$deployment} ingress/{$deployment} "
            .'secret/'.$names->secret().' secret/'.$names->secret(SecretKind::SMTP).' secret/'.$names->secret(SecretKind::OIDC).' '
            .'secret/'.$names->name('signing-cert')." -n {$namespace} --ignore-not-found",
        );
    }

    private function names(string $kubectl): ToolInstance
    {
        return ToolInstance::forInstance(ClusterTool::SIGN, (string) $this->resolveInstance($kubectl));
    }
}
