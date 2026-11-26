<?php

namespace App\Services\K8s;

use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\DnsProvider;
use RuntimeException;

/**
 * Derives a Cluster Tool's CPU and memory footprint straight from its
 * rendered Blade manifests via `ManifestResourceParser` — the template stays
 * the single source of truth so numbers can never drift from what actually
 * deploys.
 */
final class ToolFootprint
{
    private const int UNDECLARED_FLOOR_CPU_MILLICORES = 50;

    private const int UNDECLARED_FLOOR_MEMORY_BYTES = 67_108_864; // 64Mi

    /**
     * @param  array<string, mixed>  $options
     * @return array{
     *     cpu_millicores: int,
     *     memory_bytes: int,
     *     components: list<array{name: string, optional: bool, cpu_millicores: int, memory_bytes: int}>
     * }
     */
    public function measure(ClusterTool $tool, array $options = [], int $liveNodeCount = 1): array
    {
        $parser = new ManifestResourceParser;
        $totalCpu = 0;
        $totalMemory = 0;
        $components = [];

        foreach ($this->manifestEntries($tool, $options) as $entry) {
            foreach ($parser->parse($entry['yaml']) as $workload) {
                $multiplier = $workload->kind === 'DaemonSet' ? max(1, $liveNodeCount) : $workload->replicas;
                $cpu = $workload->requestsCpuMillicoresPerReplica(self::UNDECLARED_FLOOR_CPU_MILLICORES) * $multiplier;
                $memory = $workload->requestsMemoryBytesPerReplica(self::UNDECLARED_FLOOR_MEMORY_BYTES) * $multiplier;

                $totalCpu += $cpu;
                $totalMemory += $memory;

                $name = $workload->name !== '' ? $workload->name : $entry['name'];
                $components[] = [
                    'name' => $name,
                    'optional' => $entry['optional'],
                    'cpu_millicores' => $cpu,
                    'memory_bytes' => $memory,
                ];
            }
        }

        return [
            'cpu_millicores' => $totalCpu,
            'memory_bytes' => $totalMemory,
            'components' => $components,
        ];
    }

    /**
     * Render every primary workload manifest for `$tool` using placeholder
     * inputs (`footprint.invalid`). Shared by `ToolResourceRequestsTest` so
     * footprint measurement and the matrix test use the exact same views.
     *
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    public function renderManifests(ClusterTool $tool, array $options = []): array
    {
        return array_values(array_map(
            fn (array $entry): string => $entry['yaml'],
            $this->manifestEntries($tool, $options),
        ));
    }

    /**
     * @param  array<string, mixed>  $options
     * @return list<array{name: string, optional: bool, yaml: string}>
     */
    private function manifestEntries(ClusterTool $tool, array $options): array
    {
        $vars = $this->placeholderVars($tool, $options);

        $views = match ($tool) {
            ClusterTool::POCKETBASE => [['name' => 'pocketbase', 'view' => 'k8s.pocketbase.pocketbase', 'optional' => false]],
            ClusterTool::SUPABASE => [
                ['name' => 'db', 'view' => 'k8s.supabase.db', 'optional' => false],
                ['name' => 'core', 'view' => 'k8s.supabase.core', 'optional' => false],
                ['name' => 'optional', 'view' => 'k8s.supabase.optional', 'optional' => true],
                ['name' => 'gateway', 'view' => 'k8s.supabase.gateway', 'optional' => false],
            ],
            ClusterTool::DIRECTUS => [['name' => 'directus', 'view' => 'k8s.directus.directus', 'optional' => false]],
            ClusterTool::WORDPRESS => [['name' => 'wordpress', 'view' => 'k8s.wordpress.wordpress', 'optional' => false]],
            ClusterTool::N8N => [['name' => 'n8n', 'view' => 'k8s.n8n.shared', 'optional' => false]],
            ClusterTool::WINDMILL => [['name' => 'windmill', 'view' => 'k8s.windmill.shared', 'optional' => false]],
            ClusterTool::MATRIX => [
                ['name' => 'synapse', 'view' => 'k8s.matrix.matrix', 'optional' => false],
                ['name' => 'mas', 'view' => 'k8s.matrix.mas', 'optional' => true],
                ['name' => 'admin', 'view' => 'k8s.matrix.admin', 'optional' => true],
            ],
            ClusterTool::TWENTY => [['name' => 'twenty', 'view' => 'k8s.twenty.shared', 'optional' => false]],
            ClusterTool::LIVEKIT => [['name' => 'livekit', 'view' => 'k8s.livekit.livekit', 'optional' => false]],
            ClusterTool::OPENBAO => [
                ['name' => 'openbao', 'view' => 'k8s.openbao.openbao', 'optional' => false],
                ['name' => 'external-secrets', 'view' => 'k8s.openbao.eso', 'optional' => false],
            ],
            ClusterTool::NETBIRD => [
                ['name' => 'netbird-server', 'view' => 'k8s.netbird.shared', 'optional' => false],
                ['name' => 'netbird-client', 'view' => 'k8s.netbird.client', 'optional' => false],
            ],
            ClusterTool::ZITADEL => [['name' => 'zitadel', 'view' => 'k8s.zitadel.zitadel', 'optional' => false]],
            ClusterTool::VAULTWARDEN => [['name' => 'vaultwarden', 'view' => 'k8s.vaultwarden.shared', 'optional' => false]],
            ClusterTool::KUMA => [['name' => 'kuma', 'view' => 'k8s.kuma.shared', 'optional' => false]],
            ClusterTool::GRAFANA => [['name' => 'monitoring', 'view' => 'k8s.grafana.shared', 'optional' => false]],
            ClusterTool::FORGEJO => [['name' => 'forgejo', 'view' => 'k8s.forgejo.forgejo', 'optional' => false]],
            ClusterTool::METABASE => [['name' => 'metabase', 'view' => 'k8s.metabase.shared', 'optional' => false]],
            ClusterTool::GLITCHTIP => [['name' => 'glitchtip', 'view' => 'k8s.glitchtip.shared', 'optional' => false]],
            ClusterTool::OCIS => [['name' => 'ocis', 'view' => 'k8s.ocis.ocis', 'optional' => false]],
            ClusterTool::OUTLINE => [['name' => 'outline', 'view' => 'k8s.outline.shared', 'optional' => false]],
            ClusterTool::TEABLE => [['name' => 'teable', 'view' => 'k8s.teable.teable', 'optional' => false]],
            ClusterTool::ERPNEXT => [['name' => 'erpnext', 'view' => 'k8s.erpnext.erpnext', 'optional' => false]],
            ClusterTool::DOCUMENSO => [['name' => 'documenso', 'view' => 'k8s.documenso.shared', 'optional' => false]],
            ClusterTool::CHATWOOT => [['name' => 'chatwoot', 'view' => 'k8s.chatwoot.shared', 'optional' => false]],
            ClusterTool::UMAMI => [['name' => 'umami', 'view' => 'k8s.umami.shared', 'optional' => false]],
            ClusterTool::HEADLAMP => [['name' => 'headlamp', 'view' => 'k8s.headlamp.headlamp', 'optional' => false]],
            ClusterTool::STALWART => [['name' => 'stalwart', 'view' => 'k8s.stalwart.stalwart', 'optional' => false]],
            ClusterTool::BULWARK => [['name' => 'bulwark', 'view' => 'k8s.bulwark.bulwark', 'optional' => false]],
            ClusterTool::PLANKA => [['name' => 'planka', 'view' => 'k8s.planka.shared', 'optional' => false]],
            ClusterTool::KUTT => [['name' => 'kutt', 'view' => 'k8s.kutt.shared', 'optional' => false]],
            ClusterTool::PENPOT => [['name' => 'penpot', 'view' => 'k8s.penpot.shared', 'optional' => false]],
            ClusterTool::RESUME => [['name' => 'resume', 'view' => 'k8s.resume.shared', 'optional' => false]],
            ClusterTool::YOPASS => [['name' => 'yopass', 'view' => 'k8s.yopass.shared', 'optional' => false]],
            ClusterTool::SENDREC => [['name' => 'sendrec', 'view' => 'k8s.sendrec.shared', 'optional' => false]],
            ClusterTool::EXTERNAL_DNS => [['name' => 'external-dns', 'view' => 'k8s.external-dns.zone', 'optional' => false]],
            default => throw new RuntimeException("No footprint manifest mapping for {$tool->value}"),
        };

        return array_values(array_map(
            fn (array $entry): array => [
                'name' => $entry['name'],
                'optional' => $entry['optional'],
                'yaml' => view($entry['view'], $vars)->render(),
            ],
            $views,
        ));
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function placeholderVars(ClusterTool $tool, array $options): array
    {
        $noLogs = (bool) ($options['no-logs'] ?? $options['noLogs'] ?? false);
        $noTraces = (bool) ($options['no-traces'] ?? $options['noTraces'] ?? false);
        $noMonitoring = (bool) ($options['no-monitoring'] ?? $options['noMonitoring'] ?? false);
        $noPlex = (bool) ($options['no-plex'] ?? $options['noPlex'] ?? false);
        $noRealtime = (bool) ($options['no-realtime'] ?? $options['noRealtime'] ?? false);
        $noFunctions = (bool) ($options['no-functions'] ?? $options['noFunctions'] ?? false);
        $noImgproxy = (bool) ($options['no-imgproxy'] ?? $options['noImgproxy'] ?? false);
        $noPooler = (bool) ($options['no-pooler'] ?? $options['noPooler'] ?? false);

        return [
            'host' => 'tool.footprint.invalid',
            'domain' => 'footprint.invalid',
            'isLocal' => false,
            'noPlex' => $noPlex,
            'plexNamespace' => 'larakube-plex',
            'namespace' => $tool->namespace(),
            'instance' => 'tool-footprint-invalid',
            'slug' => 'footprint-invalid',
            'email' => 'ops@footprint.invalid',
            'adminEmail' => 'ops@footprint.invalid',
            'adminPassword' => 'footprint-password',
            'adminUser' => 'admin',
            'dbName' => 'tooldb',
            'storeDb' => 'tooldb',
            'dbUser' => 'tooluser',
            'dbPassword' => 'footprint-secret',
            'dbHost' => 'postgres.larakube-plex.svc.cluster.local',
            'redisIndex' => 1,
            'ssoDomain' => 'sso.footprint.invalid',
            'ssoHost' => 'sso.footprint.invalid',
            'ssoIssuer' => 'https://sso.footprint.invalid',
            'ssoClientId' => 'client-id',
            'ssoClientSecret' => 'client-secret',
            's3Endpoint' => 'http://seaweedfs.larakube-plex.svc.cluster.local:8333',
            's3PublicEndpoint' => 'https://s3.footprint.invalid',
            's3AccessKey' => 'access',
            's3SecretKey' => 'secret',
            's3Bucket' => 'tool-bucket',
            's3Region' => 'us-east-1',
            'zones' => ['footprint.invalid'],
            'ownerId' => 'larakube-footprint-invalid',
            'provider' => DnsProvider::CLOUDFLARE,
            'enableMonitoring' => ! $noMonitoring,
            'enableLogs' => ! $noLogs,
            'enableTraces' => ! $noTraces,
            'withLogs' => ! $noLogs,
            'withTraces' => ! $noTraces,
            'withRealtime' => ! $noRealtime,
            'withFunctions' => ! $noFunctions,
            'withImgproxy' => ! $noImgproxy,
            'withPooler' => ! $noPooler,
            'includeSmtp' => false,
            'smtpHost' => 'mail.footprint.invalid',
            'smtpPort' => 465,
            'smtpUser' => 'tool@footprint.invalid',
            'smtpPassword' => 'smtp-secret',
            'smtpFrom' => 'tool@footprint.invalid',
            'matrixServerName' => 'footprint.invalid',
            'serverName' => 'footprint.invalid',
            'synapseHost' => 'matrix.footprint.invalid',
            'masHost' => 'auth.matrix.footprint.invalid',
            'elementHost' => 'chat.footprint.invalid',
            'livekitHost' => 'livekit.footprint.invalid',
            'livekitApiKey' => 'devkey',
            'livekitApiSecret' => 'devsecret',
            'turnHost' => 'turn.footprint.invalid',
            'jwtSecret' => 'jwt-secret',
            'encryptionKey' => 'encryption-key',
            'secretKeyBase' => 'secret-key-base',
            'signingKey' => 'signing-key',
            'masterKey' => 'master-key',
            'authToken' => 'auth-token',
            'storageSize' => '5Gi',
            'dbStorageSize' => '5Gi',
            'image' => 'example/image:1.0.0',
            'version' => '1.0.0',
            'allowRegistration' => false,
            'relaySecret' => 'relay-secret',
            'turnUser' => 'netbird',
            'turnPassword' => 'turn-secret',
            'publicIp' => '203.0.113.10',
            'replicas' => 1,
            'imageRegistry' => 'ghcr.io',
            'clientId' => 'client-id',
            'clientSecret' => 'client-secret',
            'pvcName' => 'tool-pvc',
            'deployName' => 'tool-deploy',
            'customEnvConfigMap' => null,
            'registrationSecret' => 'reg-secret',
            'adminToken' => 'admin-token',
            'grafanaPassword' => 'grafana-pass',
            'forgejoVersion' => '10.0.0',
            's3Creds' => [
                'access' => 'access',
                'secret' => 'secret',
                'accessKey' => 'access',
                'secretKey' => 'secret',
                'endpoint' => 'http://seaweedfs.larakube-plex.svc.cluster.local:8333',
                'region' => 'us-east-1',
                'bucket' => 'ocis',
            ],
            's3InternalEndpoint' => 'http://seaweedfs.larakube-plex.svc.cluster.local:8333',
            'names' => ToolInstance::forInstance($tool, 'tool-footprint-invalid'),
            'hostPort' => false,
            'mailHost' => 'mail.footprint.invalid',
            'withExporter' => true,
            'customEnvSecret' => null,
            'secretName' => 'tool-secret',
            'masConfigHash' => 'abc123',
            'tenant' => 'tool-tenant',
            's3PublicBucket' => 'tool-public',
            'browserlessUrl' => null,
            'appName' => 'Bulwark',
            'proxied' => false,
            'bucket' => 'tool-bucket',
            'masImage' => 'ghcr.io/element-hq/matrix-authentication-service:0.13.0',
            's3PrivateBucket' => 'tool-private',
            'vpnOnly' => false,
            'smtpSecretName' => 'tool-smtp',
            'authProviders' => '',
            'oidcSecretName' => 'tool-oidc',
        ];
    }
}
