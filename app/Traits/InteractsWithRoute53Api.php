<?php

namespace App\Traits;

use App\Enums\CliTool;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/**
 * Thin wrapper over the `aws` CLI's Route53 commands — this codebase already
 * assumes `aws`/`gcloud` are installed locally for any AWS/GCP cloud workflow
 * (cloud:create --managed --provider=aws already shells `aws eks
 * update-kubeconfig`), so shelling out here matches the existing convention
 * instead of adding the AWS SDK as a new Composer dependency for one feature.
 */
trait InteractsWithRoute53Api
{
    protected function route53Available(): bool
    {
        return CliTool::AWS->isInstalled();
    }

    /**
     * The env vars the `aws` CLI needs for one-off Route53 calls, from a
     * credential bundle shaped like App\Enums\DnsProvider::ROUTE53's credentialSecretKeys().
     *
     * @param  array<string, string>  $credential
     * @return array<string, string>
     */
    protected function route53Env(array $credential): array
    {
        return [
            'AWS_ACCESS_KEY_ID' => $credential['access_key_id'] ?? '',
            'AWS_SECRET_ACCESS_KEY' => $credential['secret_access_key'] ?? '',
            'AWS_DEFAULT_REGION' => $credential['region'] ?? 'us-east-1',
        ];
    }

    /**
     * Every hosted zone these credentials can see.
     *
     * @param  array<string, string>  $env
     * @return array<string, string> [zoneId => zoneName]
     */
    protected function route53ListZones(array $env): array
    {
        $awsBin = CliTool::AWS->resolveBinary() ?? 'aws';
        $result = Process::env($env)->run("{$awsBin} route53 list-hosted-zones --output json");

        if (! $result->successful()) {
            return [];
        }

        $data = json_decode($result->output(), true);
        $zones = [];

        foreach (is_array($data) ? ($data['HostedZones'] ?? []) : [] as $zone) {
            $id = str_replace('/hostedzone/', '', (string) ($zone['Id'] ?? ''));
            $name = rtrim((string) ($zone['Name'] ?? ''), '.');
            if ($id !== '' && $name !== '') {
                $zones[$id] = $name;
            }
        }

        return $zones;
    }

    /**
     * Prove the credential can write DNS in this zone: create, then delete, a
     * short-lived TXT record. Mirrors cloudflareCanWriteDns()'s create+delete
     * probe — a read-only credential passes every other check and only fails
     * months later, at renewal.
     *
     * @param  array<string, string>  $env
     */
    protected function route53CanWriteDns(array $env, string $zoneId, string $zone): bool
    {
        $awsBin = CliTool::AWS->resolveBinary() ?? 'aws';
        $name = "_larakube-tls-check.{$zone}";
        $directory = TemporaryDirectory::make()->deleteWhenDestroyed();
        $batchFile = $directory->path('change-batch.json');

        $recordSet = [
            'Name' => $name,
            'Type' => 'TXT',
            'TTL' => 60,
            'ResourceRecords' => [['Value' => '"larakube write check"']],
        ];

        file_put_contents($batchFile, json_encode(['Changes' => [['Action' => 'UPSERT', 'ResourceRecordSet' => $recordSet]]]));
        $create = Process::env($env)->run("{$awsBin} route53 change-resource-record-sets --hosted-zone-id ".escapeshellarg($zoneId)." --change-batch file://{$batchFile}");

        if (! $create->successful()) {
            return false;
        }

        file_put_contents($batchFile, json_encode(['Changes' => [['Action' => 'DELETE', 'ResourceRecordSet' => $recordSet]]]));
        Process::env($env)->run("{$awsBin} route53 change-resource-record-sets --hosted-zone-id ".escapeshellarg($zoneId)." --change-batch file://{$batchFile}");

        return true;
    }
}
