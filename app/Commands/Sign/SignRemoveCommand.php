<?php

namespace App\Commands\Sign;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Enums\ClusterTool;
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
            "{$kubectl} get secret sign-documenso-secrets -n {$namespace} --ignore-not-found",
        )->output()) === '';
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        return $this->removeResources(
            'Removing Documenso resources...',
            "{$kubectl} delete deployment/sign-documenso service/sign ingress/sign "
            ."secret/sign-documenso-secrets -n {$namespace} --ignore-not-found",
        );
    }
}
