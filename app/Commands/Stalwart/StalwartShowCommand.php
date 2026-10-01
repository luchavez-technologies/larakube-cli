<?php

namespace App\Commands\Stalwart;

use App\Commands\Mail\MailShowCommand;
use App\Enums\ClusterTool;

class StalwartShowCommand extends MailShowCommand
{
    protected $signature = 'stalwart:show
        {environment=local : Environment whose mail server to show}
        {--email=   : Show client setup for this account instead of admin access (never shows its password — that\'s never recoverable; use mail:password to reset it)}
        {--context= : Target a specific kube-context}';

    protected $description = 'Show Stalwart admin credentials and access info, or a specific account\'s client setup';

    protected function tool(): ClusterTool
    {
        return ClusterTool::STALWART;
    }
}
