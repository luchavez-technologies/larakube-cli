<?php

namespace App\Commands\ExternalDns;

use App\Commands\Dns\DnsRemoveCommand;
use App\Enums\ClusterTool;

class ExternalDnsRemoveCommand extends DnsRemoveCommand
{
    protected $signature = 'external-dns:remove
        {environment? : Environment whose ExternalDNS to remove}
        {--zone=      : A zone to stop managing — refuses if it is one of several zones sharing a group, use --group= for those}
        {--group=     : The named multi-zone instance to remove entirely, e.g. --group=shared}
        {--all        : Remove every instance on this cluster}
        {--context=   : Target a specific kube-context}
        {--force      : Skip the confirmation prompt}';

    protected $description = 'Stop managing one or more Cloudflare zones with ExternalDNS';

    public function handle(): int
    {
        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::EXTERNAL_DNS;
    }
}
