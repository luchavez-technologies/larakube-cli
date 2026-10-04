<?php

namespace App\Commands\Share;

use App\Services\Share\DomainShare;
use App\Traits\AppliesShareEnvironment;
use App\Traits\SharesUnderDomain;

use function Laravel\Prompts\confirm;

use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class ShareDomainRemoveCommand extends Command
{
    use AppliesShareEnvironment, SharesUnderDomain;

    protected $signature = 'share:domain-remove
        {--force : Remove without asking}
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = 'Remove the project\'s public names: the connector, the tunnel and its DNS records (reads CLOUDFLARE_API_TOKEN)';

    public function handle(): int
    {
        if ($this->flag('json')) {
            $this->enableJsonMode();
        }

        $this->renderHeader();

        $project = $this->domainProject();

        if ($project === null) {
            return 1;
        }

        [, $appName, $namespace] = $project;

        $globalConfig = $this->getGlobalConfig();
        $saved = $globalConfig->getShareDomain($appName);

        if ($saved === null) {
            return $this->failed('This project has no public names from share:domain.');
        }

        if (! $this->option('force') && ! $this->cannotPrompt() && ! confirm('Remove '.implode(', ', array_map(fn (string $url): string => preg_replace('#^https://#', '', $url), $saved['urls'] ?? [])).'? The links stop working.', false)) {
            $this->laraKubeInfo('Nothing removed.');

            return 0;
        }

        $token = $this->domainToken();

        if ($token === null) {
            return $this->failed('Set CLOUDFLARE_API_TOKEN to the Cloudflare API token used for the share.');
        }

        // The connector has to be gone before Cloudflare will delete a tunnel that was running.
        $this->stopShare($namespace);

        $hosts = array_map(fn (string $url): string => preg_replace('#^https://#', '', $url), array_values($saved['urls'] ?? []));

        try {
            (new DomainShare($token))->remove((string) $saved['accountId'], (string) $saved['zoneId'], (string) $saved['tunnelId'], $hosts);
        } catch (RuntimeException $e) {
            return $this->failed($e->getMessage());
        }

        $globalConfig->setShareDomain($appName, null);
        $globalConfig->save();

        if ($this->flag('json')) {
            $this->jsonOutput(['success' => true, 'removed' => true]);

            return 0;
        }

        $this->laraKubeInfo('The public names are removed.');

        return 0;
    }
}
