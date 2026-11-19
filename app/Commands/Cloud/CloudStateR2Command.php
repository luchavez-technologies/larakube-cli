<?php

namespace App\Commands\Cloud;

use App\Data\GlobalConfigData;
use App\Services\Cloudflare\CloudflareR2Service;
use App\Traits\LaraKubeOutput;
use App\Traits\RequiresFlagsWhenNonInteractive;

use function Laravel\Prompts\password;
use function Laravel\Prompts\spin;

use LaravelZero\Framework\Commands\Command;

class CloudStateR2Command extends Command
{
    use LaraKubeOutput, RequiresFlagsWhenNonInteractive;

    protected $signature = 'cloud:state:r2
        {--token= : Cloudflare API token (needs Account → Workers R2 Storage → Edit)}
        {--bucket='.CloudflareR2Service::DEFAULT_BUCKET.' : R2 bucket name for OpenTofu state}
        {--disable : Disable R2 remote state in global config}
        {--json : Output result as JSON}';

    protected $description = 'Configure Cloudflare R2 as OpenTofu remote state backend from your Cloudflare API token';

    public function handle(CloudflareR2Service $r2): int
    {
        $isJson = (bool) $this->option('json');
        $config = GlobalConfigData::load();

        if ((bool) $this->option('disable')) {
            $config->tofuStateEnabled = false;
            $config->save();

            if ($isJson) {
                $this->line((string) json_encode(['success' => true, 'configured' => false, 'enabled' => false]));
            } else {
                $this->laraKubeInfo('Disabled Cloudflare R2 remote OpenTofu state for new stacks.');
            }

            return 0;
        }

        $token = (string) ($this->option('token') ?: (getenv('LARAKUBE_CLOUDFLARE_TOKEN') ?: ($config->cloudflareToken ?? '')));
        if ($token === '') {
            if ($this->cannotPrompt()) {
                if ($isJson) {
                    $this->line((string) json_encode(['success' => false, 'configured' => false, 'hasR2Permission' => false, 'reason' => 'Missing --token flag or saved Cloudflare token.']));

                    return 1;
                }

                $this->laraKubeError('Pass --token=<cloudflare-api-token> or configure Cloudflare DNS first.');

                return 1;
            }

            $token = password(
                label: 'Cloudflare API token (with Account → Workers R2 Storage → Edit)',
                required: true,
            );
        }

        $bucket = trim((string) ($this->option('bucket') ?: CloudflareR2Service::DEFAULT_BUCKET)) ?: CloudflareR2Service::DEFAULT_BUCKET;

        $result = $isJson
            ? $r2->detectAndConfigure($token, $bucket)
            : spin(fn () => $r2->detectAndConfigure($token, $bucket), 'Verifying Cloudflare R2 permissions & bucket...');

        if ($isJson) {
            $this->line((string) json_encode(['success' => $result['configured'], ...$result]));

            return $result['configured'] ? 0 : 1;
        }

        if (! $result['configured']) {
            $this->laraKubeWarn($result['reason'] ?? 'Could not configure Cloudflare R2 remote state.');

            return 1;
        }

        $this->laraKubeInfo("Cloudflare R2 OpenTofu state configured (bucket: {$result['bucket']}, endpoint: {$result['endpoint']}).");
        $this->line('  <fg=gray>Cross-machine note: OpenTofu state in R2 remains encrypted with each stack\'s `tofuPassphrases` key from ~/.larakube/config.json.</>');

        return 0;
    }
}
