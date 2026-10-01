<?php

namespace App\Commands\OpenBao;

use App\Commands\Secrets\SecretsRemoveCommand;
use App\Enums\ClusterTool;

class OpenBaoRemoveCommand extends SecretsRemoveCommand
{
    protected $signature = 'openbao:remove
        {environment=local  : Environment to remove OpenBao from}
        {--context=         : Target a specific kube-context (defaults to the environment\'s saved cloud target)}
        {--domain=          : Not supported — OpenBao has a single instance}
        {--purge            : Also destroy persistent data — delete OpenBao PVC and bootstrap secret. Irreversible.}
        {--force            : Skip the confirmation prompt (required for non-interactive runs)}';

    protected $description = 'Remove OpenBao secrets manager and External Secrets Operator from a cluster';

    public function handle(): int
    {
        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::OPENBAO;
    }
}
