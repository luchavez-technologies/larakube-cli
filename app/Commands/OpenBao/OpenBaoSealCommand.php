<?php

namespace App\Commands\OpenBao;

use App\Commands\Secrets\SecretsSealCommand;

class OpenBaoSealCommand extends SecretsSealCommand
{
    protected $signature = 'openbao:seal
        {environment=local : Environment whose OpenBao to seal}
        {--context= : Target a specific kube-context (defaults to current context)}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Seal OpenBao immediately — an incident-response lever that blocks all secret access';
}
