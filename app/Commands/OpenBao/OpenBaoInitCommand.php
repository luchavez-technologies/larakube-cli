<?php

namespace App\Commands\OpenBao;

use App\Commands\Secrets\SecretsInitCommand;
use App\Enums\ClusterTool;

class OpenBaoInitCommand extends SecretsInitCommand
{
    protected $signature = 'openbao:init
        {environment? : Environment this install targets — "local" (default) or a cloud env. Omit to be prompted. A non-local env prompts for + persists the secrets manager host.}
        {--context=        : Target a specific kube-context (defaults to current context)}
        {--domain=         : Base domain OR full host for OpenBao (example.com → secrets.example.com; secrets.example.com used as-is)}
        {--vpn-only        : Restrict access via NetBird VPN IP whitelisting}
        {--force           : Skip the confirmation prompt}';

    protected $description = 'Deploy OpenBao secrets manager & External Secrets Operator into larakube-secrets';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deploySecrets();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::OPENBAO;
    }
}
