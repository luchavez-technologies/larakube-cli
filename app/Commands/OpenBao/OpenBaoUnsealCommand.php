<?php

namespace App\Commands\OpenBao;

use App\Commands\Secrets\SecretsUnsealCommand;

class OpenBaoUnsealCommand extends SecretsUnsealCommand
{
    protected $signature = 'openbao:unseal
        {environment=local : Environment whose OpenBao to unseal}
        {--context= : Target a specific kube-context (defaults to current context)}';

    protected $description = 'Unseal OpenBao using its stored unseal key';
}
