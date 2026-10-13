<?php

namespace App\Commands\Cloud;

use App\Traits\EmitsJsonOutput;
use App\Traits\FailsWithJson;
use App\Traits\InteractsWithAws;
use App\Traits\InteractsWithGcp;
use App\Traits\InteractsWithGlobalConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsCommandOptions;

use function Laravel\Prompts\select;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class CloudAccountsCommand extends Command
{
    use EmitsJsonOutput, FailsWithJson, InteractsWithAws, InteractsWithGcp, InteractsWithGlobalConfig, LaraKubeOutput, ReadsCommandOptions;

    protected $signature = 'cloud:accounts
        {--provider= : Filter by provider (aws, gcp, do, hetzner)}
        {--set-default= : Account ID/profile to set as default}
        {--add : Add a new account interactively or via flags}
        {--remove= : Remove a configured account ID}
        {--name= : Name/label for the account}
        {--token= : API token for DO/Hetzner}
        {--json : Emit machine-readable JSON result}';

    protected $description = 'List and manage cloud provider accounts and profiles';

    public function handle(): int
    {
        if ($this->flag('json')) {
            $this->enableJsonMode();
        }

        $provider = $this->option('provider');

        // Handle Remove
        if ($idToRemove = $this->option('remove')) {
            return $this->handleRemove($provider, (string) $idToRemove);
        }

        // Handle Set Default
        if ($idToDefault = $this->option('set-default')) {
            return $this->handleSetDefault($provider, (string) $idToDefault);
        }

        // Handle Add
        if ($this->flag('add')) {
            return $this->handleAdd($provider);
        }

        // Otherwise: List accounts
        return $this->handleList($provider);
    }

    protected function handleList(?string $providerFilter): int
    {
        $allAccounts = $this->collectAllAccounts();

        if ($providerFilter) {
            $allAccounts = array_filter(
                $allAccounts,
                fn ($slug) => $slug === $providerFilter,
                ARRAY_FILTER_USE_KEY,
            );
        }

        if ($this->flag('json')) {
            $this->jsonOutput([
                'success' => true,
                'accounts' => $allAccounts,
            ]);

            return 0;
        }

        $rows = [];
        foreach ($allAccounts as $prov => $accounts) {
            foreach ($accounts as $acc) {
                $rows[] = [
                    strtoupper($prov),
                    $acc['name'] ?? $acc['id'],
                    $acc['id'],
                    ($acc['default'] ?? false) ? '✓ default' : '',
                    $acc['meta'] ?? '',
                ];
            }
        }

        if ($rows === []) {
            $this->laraKubeInfo('No cloud accounts configured.');

            return 0;
        }

        table(
            headers: ['Provider', 'Name', 'Account / Profile ID', 'Status', 'Details'],
            rows: $rows,
        );

        return 0;
    }

    protected function handleAdd(?string $provider): int
    {
        $prov = $provider ?: select('Which provider?', [
            'do' => 'DigitalOcean',
            'hetzner' => 'Hetzner Cloud',
            'aws' => 'Amazon Web Services',
            'gcp' => 'Google Cloud',
        ], default: 'do');

        if (in_array($prov, ['do', 'hetzner'], true)) {
            $name = $this->option('name') ?: text('Account name/label', placeholder: 'e.g. Agency Work, Acme Corp', required: true);
            $token = $this->option('token') ?: text('API token', required: true);

            $config = $this->getGlobalConfig();
            $id = $config->addCloudAccount($prov, $name, $token, asDefault: true);
            $config->save();

            if ($this->flag('json')) {
                $this->jsonOutput(['success' => true, 'provider' => $prov, 'id' => $id]);

                return 0;
            }

            $this->laraKubeInfo("Added and activated account '{$name}' for ".strtoupper($prov).'.');

            return 0;
        }

        if ($prov === 'aws') {
            $profile = $this->option('name') ?: text('AWS profile name', placeholder: 'e.g. client-prod', required: true);
            $keyId = text('AWS Access Key ID', required: true);
            $secret = text('AWS Secret Access Key', required: true);
            $region = text('AWS Region', default: 'us-east-1', required: true);

            $config = $this->getGlobalConfig();
            $this->setAwsProfile($profile);
            $config->setAwsProfile($profile);
            $config->save();

            \App\Services\Cloud\AwsCredentialsFile::save(home_path(), $keyId, $secret, $region);

            if ($this->flag('json')) {
                $this->jsonOutput(['success' => true, 'provider' => 'aws', 'profile' => $profile]);

                return 0;
            }

            $this->laraKubeInfo("Saved AWS profile '{$profile}'.");

            return 0;
        }

        if ($prov === 'gcp') {
            $input = $this->option('token') ?: ($this->option('name') ?: text('Path to Service Account JSON key or JSON string', required: true));
            $resolvedPath = str_replace('~', home_path(), trim((string) $input));
            $filePath = null;
            $data = null;

            if (is_file($resolvedPath)) {
                $filePath = $resolvedPath;
                $content = file_get_contents($filePath);
                $data = is_string($content) ? json_decode($content, true) : null;
            } else {
                $data = json_decode((string) $input, true);
            }

            if (! is_array($data) || empty($data['type']) || ($data['type'] !== 'service_account') || empty($data['client_email']) || empty($data['project_id'])) {
                return $this->failed('Input is not a valid Google Cloud Service Account JSON key.');
            }

            $projectId = (string) $data['project_id'];
            $clientEmail = (string) $data['client_email'];

            if (! $filePath) {
                $configDir = home_path().'/.larakube';
                if (! is_dir($configDir)) {
                    mkdir($configDir, 0755, true);
                }
                $filePath = $configDir."/gcp-credentials-{$projectId}.json";
                file_put_contents($filePath, (string) $input);
                chmod($filePath, 0600);
            }

            $config = $this->getGlobalConfig();
            $config->setGcpCredentials($filePath);
            $config->setGcpProjectId($projectId);
            $config->setGcpAccount($clientEmail);
            $config->save();

            if (\App\Enums\CliTool::GCLOUD->isInstalled()) {
                $gcloud = \App\Enums\CliTool::GCLOUD->resolveBinary() ?? 'gcloud';
                \Illuminate\Support\Facades\Process::run("{$gcloud} auth activate-service-account --key-file=".escapeshellarg($filePath).' 2>/dev/null');
                \Illuminate\Support\Facades\Process::run("{$gcloud} config set project ".escapeshellarg($projectId).' 2>/dev/null');
            }

            if ($this->flag('json')) {
                $this->jsonOutput([
                    'success' => true,
                    'provider' => 'gcp',
                    'id' => $clientEmail,
                    'account' => $clientEmail,
                    'project_id' => $projectId,
                ]);

                return 0;
            }

            $this->laraKubeInfo("Added Google Cloud Service Account '{$clientEmail}' for project '{$projectId}'.");

            return 0;
        }

        return $this->failed("Adding accounts for {$prov} is not supported directly via this command.");
    }

    protected function handleSetDefault(?string $provider, string $id): int
    {
        $config = $this->getGlobalConfig();

        if ($provider && in_array($provider, ['do', 'hetzner'], true)) {
            $success = $config->setDefaultCloudAccount($provider, $id);
            if (! $success) {
                return $this->failed("Account '{$id}' not found for {$provider}.");
            }
            $config->save();

            if ($this->flag('json')) {
                $this->jsonOutput(['success' => true, 'provider' => $provider, 'default' => $id]);

                return 0;
            }

            $this->laraKubeInfo("Set default account to '{$id}' for ".strtoupper($provider).'.');

            return 0;
        }

        if ($provider === 'aws') {
            $this->setAwsProfile($id);
            $config->setAwsProfile($id);
            $config->save();

            if ($this->flag('json')) {
                $this->jsonOutput(['success' => true, 'provider' => 'aws', 'default' => $id]);

                return 0;
            }

            $this->laraKubeInfo("Active AWS profile set to '{$id}'.");

            return 0;
        }

        if ($provider === 'gcp') {
            $this->setGcpAccount($id);
            $config->setGcpAccount($id);
            $config->save();

            if ($this->flag('json')) {
                $this->jsonOutput(['success' => true, 'provider' => 'gcp', 'default' => $id]);

                return 0;
            }

            $this->laraKubeInfo("Active GCP account set to '{$id}'.");

            return 0;
        }

        // Try checking DO then Hetzner if provider not specified
        foreach (['do', 'hetzner'] as $p) {
            if ($config->setDefaultCloudAccount($p, $id)) {
                $config->save();
                if ($this->flag('json')) {
                    $this->jsonOutput(['success' => true, 'provider' => $p, 'default' => $id]);

                    return 0;
                }
                $this->laraKubeInfo("Set default account to '{$id}' for ".strtoupper($p).'.');

                return 0;
            }
        }

        return $this->failed("Account '{$id}' not found.");
    }

    protected function handleRemove(?string $provider, string $id): int
    {
        $config = $this->getGlobalConfig();

        if ($provider === 'aws') {
            if (\App\Services\Cloud\AwsCredentialsFile::delete(home_path(), $id)) {
                if ($config->awsProfile === $id) {
                    $config->setAwsProfile(null);
                    $this->setAwsProfile(null);
                    $config->save();
                }

                if ($this->flag('json')) {
                    $this->jsonOutput(['success' => true, 'provider' => 'aws', 'removed' => $id]);

                    return 0;
                }

                $this->laraKubeInfo("Removed AWS profile '{$id}'.");

                return 0;
            }

            return $this->failed("AWS profile '{$id}' not found.");
        }

        if ($provider === 'gcp') {
            $gcloudBin = \App\Enums\CliTool::GCLOUD->resolveBinary() ?? 'gcloud';
            \Illuminate\Support\Facades\Process::run("{$gcloudBin} auth revoke ".escapeshellarg($id).' 2>/dev/null');

            if ($config->gcpAccount === $id) {
                $config->setGcpAccount(null);
                $this->setGcpAccount(null);
            }

            if ($config->getGcpCredentials()) {
                $credsPath = $config->getGcpCredentials();
                if (is_file($credsPath)) {
                    $content = file_get_contents($credsPath);
                    $saData = is_string($content) ? json_decode($content, true) : null;
                    if (is_array($saData) && ($saData['client_email'] ?? '') === $id) {
                        $config->setGcpCredentials(null);
                        $this->setGcpCredentials(null);
                    }
                }
            }

            $config->save();

            if ($this->flag('json')) {
                $this->jsonOutput(['success' => true, 'provider' => 'gcp', 'removed' => $id]);

                return 0;
            }

            $this->laraKubeInfo("Revoked GCP account '{$id}'.");

            return 0;
        }

        $providers = $provider ? [$provider] : ['do', 'hetzner'];

        foreach ($providers as $p) {
            if ($config->removeCloudAccount($p, $id)) {
                $config->save();

                if ($this->flag('json')) {
                    $this->jsonOutput(['success' => true, 'removed' => $id]);

                    return 0;
                }

                $this->laraKubeInfo("Removed account '{$id}' from ".strtoupper($p).'.');

                return 0;
            }
        }

        if ($provider === null && \App\Services\Cloud\AwsCredentialsFile::delete(home_path(), $id)) {
            if ($config->awsProfile === $id) {
                $config->setAwsProfile(null);
                $this->setAwsProfile(null);
                $config->save();
            }

            if ($this->flag('json')) {
                $this->jsonOutput(['success' => true, 'provider' => 'aws', 'removed' => $id]);

                return 0;
            }

            $this->laraKubeInfo("Removed AWS profile '{$id}'.");

            return 0;
        }

        return $this->failed("Could not remove account '{$id}'.");
    }

    /**
     * @return array<string, list<array{id: string, name: string, default: bool, meta?: string}>>
     */
    protected function collectAllAccounts(): array
    {
        $config = $this->getGlobalConfig();

        // DigitalOcean
        $doAccounts = array_map(fn ($acc) => [
            'id' => $acc['id'],
            'name' => $acc['name'],
            'default' => (bool) $acc['default'],
            'meta' => substr($acc['token'] ?? '', 0, 10).'...',
        ], $config->getCloudAccounts('do'));

        // Hetzner
        $hetznerAccounts = array_map(fn ($acc) => [
            'id' => $acc['id'],
            'name' => $acc['name'],
            'default' => (bool) $acc['default'],
            'meta' => substr($acc['token'] ?? '', 0, 10).'...',
        ], $config->getCloudAccounts('hetzner'));

        // AWS
        $awsAccounts = [];
        $activeAws = getenv('AWS_PROFILE') ?: ($config->getAwsProfile() ?: 'default');
        foreach ($this->listAwsProfiles() as $prof) {
            $awsAccounts[] = [
                'id' => $prof,
                'name' => $prof,
                'default' => $prof === $activeAws,
                'meta' => 'AWS CLI profile',
            ];
        }

        // GCP
        $gcpAccounts = [];
        $activeGcp = $config->getGcpAccount();
        $gcloudBin = \App\Enums\CliTool::GCLOUD->resolveBinary() ?? 'gcloud';
        foreach ($this->listGcpAccounts($gcloudBin) as $email => $isActive) {
            $gcpAccounts[$email] = [
                'id' => $email,
                'name' => $email,
                'default' => $activeGcp ? $email === $activeGcp : $isActive,
                'meta' => 'Google Cloud account',
            ];
        }

        if ($credsPath = $config->getGcpCredentials()) {
            if (is_file($credsPath)) {
                $content = file_get_contents($credsPath);
                $saData = is_string($content) ? json_decode($content, true) : null;
                if (is_array($saData) && ! empty($saData['client_email'])) {
                    $saEmail = (string) $saData['client_email'];
                    $saProj = $saData['project_id'] ?? 'gcp';
                    $gcpAccounts[$saEmail] = [
                        'id' => $saEmail,
                        'name' => $saEmail,
                        'default' => $activeGcp ? $saEmail === $activeGcp : true,
                        'meta' => "Service Account ({$saProj})",
                    ];
                }
            }
        }

        return [
            'do' => array_values($doAccounts),
            'hetzner' => array_values($hetznerAccounts),
            'aws' => array_values($awsAccounts),
            'gcp' => array_values($gcpAccounts),
        ];
    }
}
