<?php

namespace App\Commands\Cloud;

use App\Enums\CliTool;
use App\Enums\CloudProvider;
use App\Services\Cloud\LiveProviderCatalog;
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
        {--provider= : Only describe a single provider (do, hetzner, gcp, aws)}
        {--status-only : Only return provider metadata, credential status, and accounts without live instance catalog discovery}
        {--refresh : Ask the providers for current regions, sizes and prices instead of using what was fetched recently}
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = 'List cloud providers with their regions, sizes, and credential status';

    public function handle(): int
    {
        $statusOnly = (bool) $this->option('status-only');
        $onlyProvider = $this->option('provider');

        if ($onlyProvider !== null && $onlyProvider !== '') {
            $providerEnum = CloudProvider::tryFrom((string) $onlyProvider);
            if ($providerEnum === null || ! array_key_exists($providerEnum->value, CloudProvider::activeProviders())) {
                $this->laraKubeError(sprintf('Unknown provider [%s]. Available: %s', $onlyProvider, implode(', ', array_keys(CloudProvider::activeProviders()))));

                return 1;
            }
            $slugs = [$providerEnum->value];
        } else {
            $slugs = array_keys(CloudProvider::activeProviders());
        }

        $providers = [];
        foreach ($slugs as $slug) {
            $providers[] = $this->describe(CloudProvider::from($slug), $statusOnly);
        }

        if ($this->flag('json')) {
            $this->jsonOutput(['success' => true, 'providers' => $providers]);

            return 0;
        }

        if ($statusOnly) {
            table(
                headers: ['Provider', 'Slug', 'Active Account', 'Credentials'],
                rows: array_map(fn (array $p): array => [
                    $p['label'],
                    $p['slug'],
                    $p['activeAccount'] ?? '-',
                    $p['credentials']['ready'] ? 'ready' : $p['credentials']['hint'],
                ], $providers),
            );

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
     * @return array<string, mixed>
     */
    protected function describe(CloudProvider $provider, bool $statusOnly = false): array
    {
        $credentials = $this->credentialStatus($provider);
        $accountData = $this->accountsFor($provider);
        $regions = $this->pickerOptions($provider->regions());
        $defaultRegion = $provider->defaultRegion();

        $sizes = $this->pickerOptions($provider->vpsSizes());
        $managedSizes = $this->pickerOptions($provider->managedSizes());
        $defaultSize = $provider->defaultVpsSize();
        $defaultManagedSize = $provider->defaultManagedSize();
        $pricing = ['source' => 'builtin', 'asOf' => null, 'currency' => null];

        if ($statusOnly) {
            return [
                'slug' => $provider->value,
                'label' => $provider->label(),
                'regions' => $regions,
                'defaultRegion' => $defaultRegion,
                'vpsSizes' => $sizes,
                'defaultVpsSize' => $defaultSize,
                'defaultDevBoxSize' => $this->devBoxSize($sizes, $defaultSize),
                'managedSizes' => $managedSizes,
                'defaultManagedSize' => $defaultManagedSize,
                'managedProvider' => $provider->managedProvider()->value,
                'haOption' => $provider->managedProvider()->haOption(),
                'haCost' => $provider->managedProvider()->haCost(),
                'pricing' => [
                    ...$pricing,
                    'priceIsEstimated' => $provider->pricingUrl() !== null,
                    'officialPricingUrl' => $provider->pricingUrl(),
                ],
                'credentials' => $credentials,
                'accounts' => $accountData['accounts'],
                'activeAccount' => $accountData['activeAccount'],
            ];
        }

        $live = $credentials['ready'] ? (new LiveProviderCatalog)->get($provider, $this->credentialFor($provider), $this->flag('refresh')) : null;
        $priced = null;

        if ($live !== null) {
            $regions = $live['regions'];
            $sizes = array_map(fn (array $size): array => ['value' => $size['value'], 'label' => $size['label']], $live['sizes']);
            $defaultRegion = in_array($defaultRegion, array_column($regions, 'value'), true) ? $defaultRegion : $regions[0]['value'];
            $defaultSize = $this->defaultSize($provider, $live['sizes']);
            $pricing = ['source' => $live['source'], 'asOf' => $live['asOf'], 'currency' => $live['currency']];
            $priced = $live['sizes'];

            // AWS/GCP only: this is a genuinely open discovery of every
            // general-purpose/compute/memory-optimized instance type the
            // account can use, not a narrower curated subset — Managed
            // Kubernetes gets the exact same list the VPS picker just got,
            // not a second, smaller one. (DO/Hetzner's managedSizes() stays
            // static: their live sizes list is capped/sorted for the VPS
            // picker specifically, cheapest 8, and isn't a stand-in catalog.)
            if (in_array($provider, [CloudProvider::AWS, CloudProvider::GCP], true)) {
                $managedSizes = $sizes;
                $managedValues = array_column($managedSizes, 'value');
                if ($managedValues !== [] && ! in_array($defaultManagedSize, $managedValues, true)) {
                    $defaultManagedSize = $managedValues[0];
                }
            }
        }

        return [
            'slug' => $provider->value,
            'label' => $provider->label(),
            'regions' => $regions,
            'defaultRegion' => $defaultRegion,
            'vpsSizes' => $sizes,
            'defaultVpsSize' => $defaultSize,
            'defaultDevBoxSize' => $this->devBoxSize($priced ?? $sizes, $defaultSize),
            'managedSizes' => $managedSizes,
            'defaultManagedSize' => $defaultManagedSize,
            'managedProvider' => $provider->managedProvider()->value,
            'haOption' => $provider->managedProvider()->haOption(),
            'haCost' => $provider->managedProvider()->haCost(),
            'pricing' => [
                ...$pricing,
                'priceIsEstimated' => $provider->pricingUrl() !== null,
                'officialPricingUrl' => $provider->pricingUrl(),
            ],
            'credentials' => $credentials,
            'accounts' => $accountData['accounts'],
            'activeAccount' => $accountData['activeAccount'],
        ];
    }

    /**
     * @return array{accounts: list<array{id: string, label: string, isDefault: bool, meta?: string}>, activeAccount: ?string}
     */
    protected function accountsFor(CloudProvider $provider): array
    {
        $config = $this->getGlobalConfig();

        return match ($provider) {
            CloudProvider::DO => (function () use ($config) {
                $accounts = array_map(fn ($acc) => [
                    'id' => $acc['id'],
                    'label' => $acc['name'],
                    'isDefault' => (bool) $acc['default'],
                    'meta' => substr($acc['token'] ?? '', 0, 10).'...',
                ], $config->getCloudAccounts('do'));
                $def = $config->getDefaultCloudAccount('do');

                return ['accounts' => array_values($accounts), 'activeAccount' => $def['id'] ?? null];
            })(),
            CloudProvider::HETZNER => (function () use ($config) {
                $accounts = array_map(fn ($acc) => [
                    'id' => $acc['id'],
                    'label' => $acc['name'],
                    'isDefault' => (bool) $acc['default'],
                    'meta' => substr($acc['token'] ?? '', 0, 10).'...',
                ], $config->getCloudAccounts('hetzner'));
                $def = $config->getDefaultCloudAccount('hetzner');

                return ['accounts' => array_values($accounts), 'activeAccount' => $def['id'] ?? null];
            })(),
            CloudProvider::AWS => (function () use ($config) {
                $profiles = $this->listAwsProfiles();
                $active = getenv('AWS_PROFILE') ?: ($config->getAwsProfile() ?: (in_array('default', $profiles, true) ? 'default' : ($profiles[0] ?? null)));
                $accounts = array_map(fn ($prof) => [
                    'id' => $prof,
                    'label' => $prof,
                    'isDefault' => $prof === $active,
                ], $profiles);

                return ['accounts' => array_values($accounts), 'activeAccount' => $active];
            })(),
            CloudProvider::GCP => (function () use ($config) {
                $gcloudBin = CliTool::GCLOUD->resolveBinary() ?? 'gcloud';
                $accountsMap = $this->listGcpAccounts($gcloudBin);
                $active = $config->getGcpAccount();
                $accounts = array_map(fn ($email, $isActive) => [
                    'id' => $email,
                    'label' => $email,
                    'isDefault' => $active ? $email === $active : $isActive,
                ], array_keys($accountsMap), array_values($accountsMap));

                return ['accounts' => array_values($accounts), 'activeAccount' => $active];
            })(),
        };
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

    /**
     * Keep the built-in default while the provider still sells it; otherwise the
     * cheapest size with at least 4 GB (the smallest the tools run comfortably on).
     *
     * @param  list<array{value: string, label: string, monthly: float, currency: string}>  $sizes
     */
    private function defaultSize(CloudProvider $provider, array $sizes): string
    {
        $values = array_column($sizes, 'value');

        if (in_array($provider->defaultVpsSize(), $values, true)) {
            return $provider->defaultVpsSize();
        }

        foreach ($sizes as $size) {
            if (preg_match('/(\d+) GB RAM/', $size['label'], $match) && (int) $match[1] >= 4) {
                return $size['value'];
            }
        }

        return $sizes[0]['value'];
    }

    /**
     * The size a dev box starts with: the cheapest with at least 8 GB of RAM, since Podman, a local
     * cluster, the Commons and an app together use close to 2 GB and a second app or a build needs room.
     * Falls back to the provider's default when no size says its memory.
     *
     * @param  list<array{value: string, label: string, monthly?: float}>  $sizes
     */
    private function devBoxSize(array $sizes, string $fallback): string
    {
        $fits = array_filter($sizes, fn (array $size): bool => preg_match('/(\d+(?:\.\d+)?) GB RAM/', $size['label'], $match) === 1 && (float) $match[1] >= 8);

        if ($fits === []) {
            return $fallback;
        }

        usort($fits, fn (array $a, array $b): int => ($a['monthly'] ?? 0) <=> ($b['monthly'] ?? 0));

        return $fits[0]['value'];
    }

    /**
     * What LiveProviderCatalog::get() needs to reach this provider: a token
     * (DO/Hetzner), the `aws` CLI's resolved env vars (AWS), or the active
     * GCP project ID.
     */
    private function credentialFor(CloudProvider $provider): mixed
    {
        return match ($provider) {
            CloudProvider::DO => $this->getDoToken() ?: (getenv('TF_VAR_do_token') ?: null),
            CloudProvider::HETZNER => $this->getHetznerToken(),
            CloudProvider::AWS => $this->buildAwsEnv(),
            CloudProvider::GCP => $this->getGcpProjectId(),
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

        $process = Process::env($this->buildAwsEnv())->run("{$bin} sts get-caller-identity{$profileArg}");
        if (! $process->successful()) {
            $err = trim($process->errorOutput() ?: $process->output());
            if (preg_match('/An error occurred \(([^)]+)\)/', $err, $matches)) {
                return $this->status(false, "AWS authentication failed ({$matches[1]}).");
            }

            return $this->status(false, 'Not logged in to AWS.');
        }

        return $this->status(true);
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
