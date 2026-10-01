<?php

namespace App\Commands\ExternalDns;

use App\Commands\Dns\DnsListCommand;
use App\Enums\ClusterTool;

class ExternalDnsListCommand extends DnsListCommand
{
    protected $signature = 'external-dns:list
        {environment? : Environment whose zones to list}
        {--context=   : Target a specific kube-context}
        {--json       : Emit one machine-readable JSON array on stdout}';

    protected $description = 'List the Cloudflare zones this cluster manages with ExternalDNS';

    public function handle(): int
    {
        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::EXTERNAL_DNS;
    }
}
