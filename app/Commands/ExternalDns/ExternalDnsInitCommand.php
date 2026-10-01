<?php

namespace App\Commands\ExternalDns;

use App\Commands\Dns\DnsInitCommand;
use App\Enums\ClusterTool;

class ExternalDnsInitCommand extends DnsInitCommand
{
    protected $signature = 'external-dns:init
        {environment?        : Environment this install targets (a cloud env — ExternalDNS is not supported locally)}
        {--cloudflare-token= : API token — every zone it can see is discovered and managed, unless --zone= narrows that. Or set LARAKUBE_CLOUDFLARE_TOKEN}
        {--zone=*            : Optional — restrict to a subset of what the token can see. Omit to manage every zone the token has access to.}
        {--group=            : Stable name for this instance. Default: the sole zone\'s own slug (unchanged single-zone behavior) — required when 2+ zones are in scope}
        {--context=          : Target a specific kube-context}
        {--force             : Skip the confirmation prompt}';

    protected $description = 'Deploy an ExternalDNS instance for one or more Cloudflare zones sharing a token';

    public function handle(): int
    {
        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::EXTERNAL_DNS;
    }
}
