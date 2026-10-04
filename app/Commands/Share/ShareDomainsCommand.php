<?php

namespace App\Commands\Share;

use App\Services\Share\DomainShare;
use App\Traits\SharesUnderDomain;
use LaravelZero\Framework\Commands\Command;

class ShareDomainsCommand extends Command
{
    use SharesUnderDomain;

    protected $signature = 'share:domains
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = 'List the Cloudflare domains a token can share projects under (reads CLOUDFLARE_API_TOKEN)';

    public function handle(): int
    {
        if ($this->flag('json')) {
            $this->enableJsonMode();
        }

        $token = $this->domainToken();

        if ($token === null) {
            return $this->failed('Set CLOUDFLARE_API_TOKEN to a Cloudflare API token to list its domains.');
        }

        $zones = $this->domainZones(new DomainShare($token));

        if ($zones === null) {
            return 1;
        }

        $names = array_column($zones, 'name');

        if ($this->flag('json')) {
            $this->jsonOutput(['success' => true, 'domains' => $names]);

            return 0;
        }

        foreach ($names as $name) {
            $this->line($name);
        }

        return 0;
    }
}
