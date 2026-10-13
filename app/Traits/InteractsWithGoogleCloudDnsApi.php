<?php

namespace App\Traits;

use App\Enums\CliTool;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/**
 * Thin wrapper over the `gcloud` CLI's Cloud DNS commands.
 */
trait InteractsWithGoogleCloudDnsApi
{
    protected function googleCloudDnsAvailable(): bool
    {
        return CliTool::GCLOUD->isInstalled();
    }

    /**
     * Environment variables and options for one-off Google Cloud DNS CLI calls.
     *
     * @param  array<string, string>  $credential
     * @return array<string, string>
     */
    protected function googleCloudDnsEnv(array $credential, ?string $saFile = null): array
    {
        $env = [];

        if ($saFile !== null && file_exists($saFile)) {
            $env['GOOGLE_APPLICATION_CREDENTIALS'] = $saFile;
        }

        if (! empty($credential['project_id'])) {
            $env['CLOUDSDK_CORE_PROJECT'] = $credential['project_id'];
        }

        return $env;
    }

    /**
     * Every managed zone in this Google Cloud project.
     *
     * @param  array<string, string>  $credential
     * @return array<string, string> [zoneName => dnsName]
     */
    protected function googleCloudDnsListZones(array $credential): array
    {
        $gcloud = CliTool::GCLOUD->resolveBinary() ?? 'gcloud';
        $directory = TemporaryDirectory::make()->deleteWhenDestroyed();
        $saFile = null;

        if (! empty($credential['credentials.json'])) {
            $saFile = $directory->path('credentials.json');
            file_put_contents($saFile, $credential['credentials.json']);
        }

        $env = $this->googleCloudDnsEnv($credential, $saFile);
        $project = escapeshellarg($credential['project_id'] ?? '');

        $result = Process::env($env)->run("{$gcloud} dns managed-zones list --project={$project} --format=json");

        if (! $result->successful()) {
            return [];
        }

        $data = json_decode($result->output(), true);
        $zones = [];

        foreach (is_array($data) ? $data : [] as $zone) {
            $name = (string) ($zone['name'] ?? '');
            $dnsName = rtrim((string) ($zone['dnsName'] ?? ''), '.');
            if ($name !== '' && $dnsName !== '') {
                $zones[$name] = $dnsName;
            }
        }

        return $zones;
    }

    /**
     * Prove the credential can write DNS records in this Google Cloud DNS zone.
     *
     * @param  array<string, string>  $credential
     */
    protected function googleCloudDnsCanWriteDns(array $credential, string $zoneName, string $dnsName): bool
    {
        $gcloud = CliTool::GCLOUD->resolveBinary() ?? 'gcloud';
        $directory = TemporaryDirectory::make()->deleteWhenDestroyed();
        $saFile = null;

        if (! empty($credential['credentials.json'])) {
            $saFile = $directory->path('credentials.json');
            file_put_contents($saFile, $credential['credentials.json']);
        }

        $env = $this->googleCloudDnsEnv($credential, $saFile);
        $project = escapeshellarg($credential['project_id'] ?? '');
        $zoneEscaped = escapeshellarg($zoneName);
        $testHost = "_larakube-tls-check.{$dnsName}.";

        // Add transaction
        $addRes = Process::env($env)->run("{$gcloud} dns record-sets transaction start --zone={$zoneEscaped} --project={$project} && {$gcloud} dns record-sets transaction add \"larakube write check\" --name={$testHost} --ttl=60 --type=TXT --zone={$zoneEscaped} --project={$project} && {$gcloud} dns record-sets transaction execute --zone={$zoneEscaped} --project={$project}");

        if (! $addRes->successful()) {
            return false;
        }

        // Cleanup transaction
        Process::env($env)->run("{$gcloud} dns record-sets transaction start --zone={$zoneEscaped} --project={$project} && {$gcloud} dns record-sets transaction remove \"larakube write check\" --name={$testHost} --ttl=60 --type=TXT --zone={$zoneEscaped} --project={$project} && {$gcloud} dns record-sets transaction execute --zone={$zoneEscaped} --project={$project}");

        return true;
    }
}
