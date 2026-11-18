<?php

use App\Enums\ClusterTool;
use App\Enums\DnsProvider;
use App\Services\K8s\ManifestResourceParser;

/**
 * Renders every shipped ClusterTool's primary workload manifest(s) with
 * placeholder inputs so the capacity guard's parser can verify every
 * Deployment/DaemonSet container declares CPU and memory requests.
 *
 * @return list<string>
 */
function renderShippedToolManifests(ClusterTool $tool): array
{
    $vars = [
        'host' => 'tool.example.com',
        'domain' => 'example.com',
        'isLocal' => false,
        'noPlex' => false,
        'plexNamespace' => 'larakube-plex',
        'namespace' => $tool->namespace(),
        'instance' => 'tool-example-com',
        'slug' => 'example-com',
        'email' => 'ops@example.com',
        'adminEmail' => 'ops@example.com',
        'adminPassword' => 'secret-password',
        'adminUser' => 'admin',
        'dbName' => 'tooldb',
        'storeDb' => 'tooldb',
        'dbUser' => 'tooluser',
        'dbPassword' => 'secret',
        'dbHost' => 'postgres.larakube-plex.svc.cluster.local',
        'redisIndex' => 1,
        'ssoDomain' => 'sso.example.com',
        'ssoHost' => 'sso.example.com',
        'ssoIssuer' => 'https://sso.example.com',
        'ssoClientId' => 'client-id',
        'ssoClientSecret' => 'client-secret',
        's3Endpoint' => 'http://seaweedfs.larakube-plex.svc.cluster.local:8333',
        's3PublicEndpoint' => 'https://s3.example.com',
        's3AccessKey' => 'access',
        's3SecretKey' => 'secret',
        's3Bucket' => 'tool-bucket',
        's3Region' => 'us-east-1',
        'zones' => ['example.com'],
        'ownerId' => 'larakube-test-example-com',
        'provider' => DnsProvider::CLOUDFLARE,
        'enableMonitoring' => true,
        'enableLogs' => true,
        'enableTraces' => true,
        'includeSmtp' => false,
        'smtpHost' => 'mail.example.com',
        'smtpPort' => 465,
        'smtpUser' => 'tool@example.com',
        'smtpPassword' => 'smtp-secret',
        'smtpFrom' => 'tool@example.com',
        'matrixServerName' => 'example.com',
        'serverName' => 'example.com',
        'synapseHost' => 'matrix.example.com',
        'masHost' => 'auth.matrix.example.com',
        'elementHost' => 'chat.example.com',
        'livekitHost' => 'livekit.example.com',
        'livekitApiKey' => 'devkey',
        'livekitApiSecret' => 'devsecret',
        'turnHost' => 'turn.example.com',
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
        'names' => App\Data\ToolInstance::forInstance($tool, 'tool-example-com'),
        'hostPort' => false,
        'mailHost' => 'mail.example.com',
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

    $views = match ($tool) {
        ClusterTool::POCKETBASE => ['k8s.pocketbase.pocketbase'],
        ClusterTool::DIRECTUS => ['k8s.directus.directus'],
        ClusterTool::WORDPRESS => ['k8s.wordpress.wordpress'],
        ClusterTool::N8N => ['k8s.n8n.shared'],
        ClusterTool::WINDMILL => ['k8s.windmill.shared'],
        ClusterTool::MATRIX => ['k8s.matrix.matrix', 'k8s.matrix.mas', 'k8s.matrix.admin'],
        ClusterTool::TWENTY => ['k8s.twenty.shared'],
        ClusterTool::LIVEKIT => ['k8s.livekit.livekit'],
        ClusterTool::OPENBAO => ['k8s.openbao.openbao', 'k8s.openbao.eso'],
        ClusterTool::NETBIRD => ['k8s.netbird.shared', 'k8s.netbird.client'],
        ClusterTool::ZITADEL => ['k8s.zitadel.zitadel'],
        ClusterTool::VAULTWARDEN => ['k8s.vaultwarden.shared'],
        ClusterTool::GRAFANA => ['k8s.grafana.shared'],
        ClusterTool::FORGEJO => ['k8s.forgejo.forgejo'],
        ClusterTool::METABASE => ['k8s.metabase.shared'],
        ClusterTool::GLITCHTIP => ['k8s.glitchtip.shared'],
        ClusterTool::OCIS => ['k8s.ocis.ocis'],
        ClusterTool::OUTLINE => ['k8s.outline.shared'],
        ClusterTool::TEABLE => ['k8s.teable.teable'],
        ClusterTool::DOCUMENSO => ['k8s.documenso.shared'],
        ClusterTool::CHATWOOT => ['k8s.chatwoot.shared'],
        ClusterTool::HEADLAMP => ['k8s.headlamp.headlamp'],
        ClusterTool::STALWART => ['k8s.stalwart.stalwart'],
        ClusterTool::BULWARK => ['k8s.bulwark.bulwark'],
        ClusterTool::PLANKA => ['k8s.planka.shared'],
        ClusterTool::KUTT => ['k8s.kutt.shared'],
        ClusterTool::PENPOT => ['k8s.penpot.shared'],
        ClusterTool::RESUME => ['k8s.resume.shared'],
        ClusterTool::YOPASS => ['k8s.yopass.shared'],
        ClusterTool::SENDREC => ['k8s.sendrec.shared'],
        ClusterTool::EXTERNAL_DNS => ['k8s.external-dns.zone'],
        default => throw new RuntimeException("No manifest view mapped for shipped tool {$tool->value}"),
    };

    return array_map(fn (string $view): string => view($view, $vars)->render(), $views);
}

test('every shipped ClusterTool container declares cpu and memory requests', function (ClusterTool $tool): void {
    $parser = new ManifestResourceParser;
    $workloads = [];

    foreach (renderShippedToolManifests($tool) as $rendered) {
        foreach ($parser->parse($rendered) as $workload) {
            $workloads[] = $workload;
        }
    }

    expect($workloads)->not->toBeEmpty("Expected at least one Deployment or DaemonSet for {$tool->value}");

    foreach ($workloads as $workload) {
        foreach ($workload->containers as $container) {
            expect($container->declared)
                ->toBeTrue("{$tool->value} {$workload->kind}/{$workload->name} container {$container->name} is missing resources:")
                ->and($container->requestsCpuMillicores)
                ->not->toBeNull("{$tool->value} {$workload->kind}/{$workload->name} container {$container->name} is missing resources.requests.cpu")
                ->and($container->requestsMemoryBytes)
                ->not->toBeNull("{$tool->value} {$workload->kind}/{$workload->name} container {$container->name} is missing resources.requests.memory");
        }
    }
})->with(fn () => array_map(fn (ClusterTool $tool) => [$tool], ClusterTool::shippedCases()));

test('every Traefik manifest container declares cpu and memory requests', function (string $view): void {
    $rendered = view($view, match ($view) {
        'k8s.traefik-cloud' => ['email' => 'ops@example.com', 'ip' => '203.0.113.10'],
        'k8s.traefik-managed' => ['email' => 'ops@example.com', 'loadBalancerName' => 'lb-example'],
        default => [],
    })->render();

    $workloads = (new ManifestResourceParser)->parse($rendered);
    expect($workloads)->not->toBeEmpty();

    foreach ($workloads as $workload) {
        foreach ($workload->containers as $container) {
            expect($container->declared)->toBeTrue()
                ->and($container->requestsCpuMillicores)->not->toBeNull()
                ->and($container->requestsMemoryBytes)->not->toBeNull();
        }
    }
})->with(['k8s.traefik-cloud', 'k8s.traefik-managed', 'k8s.traefik-install']);
