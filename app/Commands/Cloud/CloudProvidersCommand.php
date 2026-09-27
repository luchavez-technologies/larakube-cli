<?php

namespace App\Commands\Cloud;

use App\Enums\CliTool;
use App\Enums\CloudProvider;
use App\Traits\EmitsJsonOutput;
use App\Traits\InteractsWithAws;
use App\Traits\InteractsWithGcp;
use App\Traits\InteractsWithHetzner;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsCommandOptions;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

/**
 * Read-only catalog of what `cloud:create` can provision: each provider's
 * regions and sizes, and whether this machine already holds the credentials
 * a non-interactive `cloud:create` run would need. GUIs (LaraKube Desktop,
 * LaraKube Cloud) populate their pickers from this rather than duplicating
 * the CloudProvider enum.
 */
class CloudProvidersCommand extends Command
{
    use EmitsJsonOutput, InteractsWithAws, InteractsWithGcp, InteractsWithHetzner, LaraKubeOutput, ReadsCommandOptions;

    protected $signature = 'cloud:providers
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = 'List cloud providers with their regions, sizes, and credential status';

    public function handle(): int
    {
        $providers = [];
        foreach (array_keys(CloudProvider::activeProviders()) as $slug) {
            $providers[] = $this->describe(CloudProvider::from($slug));
        }

        if ($this->flag('json')) {
            $this->jsonOutput(['success' => true, 'providers' => $providers]);

            return 0;
        }

        table(
            headers: ['Provider', 'Slug', 'Regions', 'VPS sizes', 'Credentials'],
            rows: array_map(fn (array $p): array => [
                $p['label'],
                $p['slug'],
                (string) count($p['regions']),
                (string) count($p['vpsSizes']),
                $p['credentials']['ready'] ? 'ready' : $p['credentials']['hint'],
            ], $providers),
        );

        return 0;
    }

    /**
     * @return array{slug: string, label: string, regions: list<array{value: string, label: string}>, defaultRegion: string, vpsSizes: list<array{value: string, label: string}>, defaultVpsSize: string, managedSizes: list<array{value: string, label: string}>, defaultManagedSize: string, credentials: array{ready: bool, hint: ?string}}
     */
    protected function describe(CloudProvider $provider): array
    {
        return [
            'slug' => $provider->value,
            'label' => $provider->label(),
            'regions' => $this->pickerOptions($provider->regions()),
            'defaultRegion' => $provider->defaultRegion(),
            'vpsSizes' => $this->pickerOptions($provider->vpsSizes()),
            'defaultVpsSize' => $provider->defaultVpsSize(),
            'managedSizes' => $this->pickerOptions($provider->managedSizes()),
            'defaultManagedSize' => $provider->defaultManagedSize(),
            'credentials' => $this->credentialStatus($provider),
        ];
    }

    /**
     * Mirrors the non-interactive branches of the ensure*() credential
     * checks, without prompting or persisting anything.
     *
     * @return array{ready: bool, hint: ?string}
     */
    protected function credentialStatus(CloudProvider $provider): array
    {
        return match ($provider) {
            CloudProvider::DO => $this->status(
                (bool) ($this->getDoToken() ?: getenv('TF_VAR_do_token')),
                'No DigitalOcean API token saved.',
            ),
            CloudProvider::HETZNER => $this->status(
                (bool) $this->getHetznerToken(),
                'No Hetzner Cloud API token saved.',
            ),
            CloudProvider::GCP => $this->gcpStatus(),
            CloudProvider::AWS => $this->awsStatus(),
        };
    }

    /** @return array{ready: bool, hint: ?string} */
    private function gcpStatus(): array
    {
        if (! $this->getGcpProjectId()) {
            return $this->status(false, 'No Google Cloud project selected.');
        }

        if ($this->getGcpCredentials()) {
            return $this->status(true);
        }

        if (! CliTool::GCLOUD->isInstalled()) {
            return $this->status(false, 'Google Cloud CLI (gcloud) is not installed.');
        }

        $bin = CliTool::GCLOUD->resolveBinary() ?? 'gcloud';

        return $this->status(
            Process::run("{$bin} auth print-access-token 2>/dev/null")->successful(),
            'Not logged in to Google Cloud.',
        );
    }

    /** @return array{ready: bool, hint: ?string} */
    private function awsStatus(): array
    {
        if (! CliTool::AWS->isInstalled()) {
            return $this->status(false, 'AWS CLI is not installed.');
        }

        $bin = CliTool::AWS->resolveBinary() ?? 'aws';
        $profile = $this->getAwsProfile();
        $profileArg = $profile ? ' --profile '.escapeshellarg($profile) : '';

        return $this->status(
            Process::env($this->buildAwsEnv())->run("{$bin} sts get-caller-identity{$profileArg} 2>/dev/null")->successful(),
            'Not logged in to AWS.',
        );
    }

    /** @return array{ready: bool, hint: ?string} */
    private function status(bool $ready, ?string $hint = null): array
    {
        return ['ready' => $ready, 'hint' => $ready ? null : $hint];
    }

    /**
     * @param  array<string, string>  $options
     * @return list<array{value: string, label: string}>
     */
    private function pickerOptions(array $options): array
    {
        return array_map(
            fn (string $value, string $label): array => ['value' => $value, 'label' => $label],
            array_keys($options),
            array_values($options),
        );
    }
}
