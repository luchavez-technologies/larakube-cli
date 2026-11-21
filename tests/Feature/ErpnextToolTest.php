<?php

use App\Commands\Erpnext\ErpnextInitCommand;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use App\Services\K8s\ToolFootprint;
use App\Services\Tools\ToolInitCommands;
use App\Services\Tools\ToolInitSpec;
use App\Vendors\ErpnextTool;

test('ClusterTool::ERPNEXT is registered across vendor, service, init spec, commands, and footprint', function (): void {
    $tool = ClusterTool::ERPNEXT;

    expect($tool->vendor())->toBeInstanceOf(ErpnextTool::class)
        ->and($tool->service())->toBe(SharedClusterService::ERPNEXT)
        ->and($tool->getLabel())->toBe('ERPNext (Accounting & ERP)')
        ->and($tool->brandName())->toBe('ERPNext')
        ->and($tool->hasInstanceAwareRemoval())->toBeTrue()
        ->and(ToolInitCommands::for($tool))->toBeInstanceOf(ErpnextInitCommand::class)
        ->and(ToolInitSpec::description($tool))->toContain('ERPNext')
        ->and(app(ToolFootprint::class)->renderManifests($tool))->toBeArray()->not->toBeEmpty();

    $caps = $tool->commonsCapabilities();
    expect($caps['databases'])->toBe(['mariadb'])
        ->and($caps['cache'])->toBe(['redis'])
        ->and($caps['auth'])->toBe(['oidc'])
        ->and($caps['mail'])->toBe(['smtp']);
});

test('ErpnextTool declares OIDC and SMTP wiring schemas targeting the backend container', function (): void {
    $oidc = ClusterTool::ERPNEXT->oidcEnv(instance: 'erpnext-example-com');
    $smtp = ClusterTool::ERPNEXT->smtpEnv(instance: 'erpnext-example-com');

    expect($oidc)->toBeArray()
        ->and($oidc['container'])->toBe('backend')
        ->and($oidc['redirect_path'])->toBe('/api/method/frappe.integrations.oauth2_logins.custom/zitadel')
        ->and($oidc['vars']['client_id'])->toBe('LARAKUBE_OIDC_CLIENT_ID')
        ->and($smtp)->toBeArray()
        ->and($smtp['container'])->toBe('backend')
        ->and($smtp['vars']['host'])->toBe('LARAKUBE_SMTP_HOST');
});

test('k8s.erpnext.erpnext renders a single-pod Recreate deployment with RWO sites PVC and MariaDB/Redis wiring', function (): void {
    $host = 'erpnext.example.com';
    $names = ToolInstance::forHost(ClusterTool::ERPNEXT, $host);

    $yaml = view('k8s.erpnext.erpnext', [
        'host' => $host,
        'names' => $names,
        'dbName' => $names->database(),
        'plexNamespace' => 'larakube-plex',
        'redisIndex' => 3,
        'vpnOnly' => true,
        'isLocal' => false,
        'proxied' => true,
    ])->render();

    expect($yaml)
        ->toContain('kind: PersistentVolumeClaim')
        ->toContain('ReadWriteOnce')
        ->toContain('kind: Deployment')
        ->toContain('type: Recreate')
        ->toContain('name: frontend')
        ->toContain('name: backend')
        ->toContain('name: websocket')
        ->toContain('name: worker')
        ->toContain('name: scheduler')
        ->toContain('mariadb.larakube-plex.svc.cluster.local')
        ->toContain('redis://redis.larakube-plex.svc.cluster.local:6379/3')
        ->toContain('traefik.ingress.kubernetes.io/router.middlewares:')
        ->toContain('external-dns.alpha.kubernetes.io/cloudflare-proxied: "true"')
        ->toContain("host: {$host}");
});

test('k8s.erpnext.erpnext boots a fresh sites volume the way the image expects', function (): void {
    $host = 'erpnext.example.com';
    $names = ToolInstance::forHost(ClusterTool::ERPNEXT, $host);

    $yaml = view('k8s.erpnext.erpnext', [
        'host' => $host,
        'names' => $names,
        'dbName' => $names->database(),
        'plexNamespace' => 'larakube-plex',
        'redisIndex' => 3,
    ])->render();

    expect($yaml)
        // bench finds its apps through sites/apps.txt, which an empty volume lacks
        ->toContain('ls -1 apps > sites/apps.txt')
        // `command:` would replace the image entrypoint that links the built assets
        ->not->toMatch('/^\s+command:/m')
        ->toContain('args: ["nginx-entrypoint.sh"]')
        // the bench's own Python, which has frappe installed; the system python3 does not
        ->toContain('/home/frappe/frappe-bench/env/bin/python /bootstrap/sync-wiring.py')
        // gunicorn binds 127.0.0.1, so a probe on the pod IP would never pass
        ->not->toContain('port: 8000')
        ->toMatch('/image: docker\.io\/frappe\/erpnext:v\d+\.\d+\.\d+$/m')
        ->toContain("site_cfg['db_password'] = os.environ['DB_PASSWORD']")
        ->toContain('value: "https://erpnext.example.com"')
        ->toContain('bench --site frontend migrate')
        ->not->toContain('--skip-failing')
        ->not->toContain('|| true');
});

test('ERPNext syncs its OpenBao keys into the db-password and admin-password Secret keys', function (): void {
    $config = ClusterTool::ERPNEXT->openbaoSyncConfig('main');

    expect($config['secret'])->toBe(ToolInstance::forInstance(ClusterTool::ERPNEXT, 'main')->secret())
        ->and($config['keyMap'])->toBe([
            'ERPNEXT_DB_PASSWORD' => 'db-password',
            'ERPNEXT_ADMIN_PASSWORD' => 'admin-password',
        ]);
});
