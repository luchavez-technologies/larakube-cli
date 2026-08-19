<?php

use App\Commands\Design\DesignInitCommand;
use App\Commands\Design\DesignRemoveCommand;
use App\Commands\Design\DesignShowCommand;
use Illuminate\Support\Facades\Process;

function designCommonsSpec(?string $s3Host = null): array
{
    $seaweedfs = ['enabled' => true, 'port' => 8333];
    if ($s3Host !== null) {
        $seaweedfs['host'] = $s3Host;
    }

    return [
        'services' => [
            'postgres' => ['enabled' => true],
            'redis' => ['enabled' => true],
            'seaweedfs' => $seaweedfs,
        ],
    ];
}

function fakeDesignInitProcess(?string $s3Host = null, ?string &$appliedManifest = null, int $applyExitCode = 0): void
{
    $spec = designCommonsSpec($s3Host);

    Process::fake(function ($process) use ($spec, &$appliedManifest, $applyExitCode) {
        $cmd = $process->command;

        if (str_contains($cmd, 'apply -f')) {
            preg_match('/apply -f (\'[^\']*\'|"[^"]*"|\S+)/', $cmd, $m);
            $path = trim($m[1] ?? '', '\'"');
            if ($path !== '' && file_exists($path) && str_contains($path, 'larakube-design-penpot')) {
                $appliedManifest = file_get_contents($path);
            }

            return Process::result(output: 'applied', exitCode: $applyExitCode);
        }

        return match (true) {
            str_contains($cmd, 'get configmap plex-commons') => Process::result(output: json_encode($spec)),
            str_contains($cmd, 'get configmap plex-registry') => Process::result(output: '', exitCode: 1),
            str_contains($cmd, 'S3_ACCESS_KEY') => Process::result(output: base64_encode('larakube')),
            str_contains($cmd, 'S3_SECRET_KEY') => Process::result(output: base64_encode('s3-secret')),
            str_contains($cmd, 'rollout status') => Process::result(output: 'deployment "design-penpot-backend" successfully rolled out'),
            default => Process::result(output: ''),
        };
    });
}

test('design:init deploys Penpot stack into larakube-shared with Postgres, Redis, and S3 endpoints', function (): void {
    $appliedManifest = null;
    fakeDesignInitProcess('files.example.com', $appliedManifest);

    $this->artisan(DesignInitCommand::class, [
        'environment' => 'local',
        '--admin-email' => 'admin@example.com',
        '--no-interaction' => true,
    ])->assertExitCode(0);

    expect($appliedManifest)->not->toBeNull()
        ->and($appliedManifest)->toContain('penpotapp/backend:2.17')
        ->and($appliedManifest)->toContain('penpotapp/frontend:2.17')
        ->and($appliedManifest)->toContain('PENPOT_DATABASE_USERNAME')
        ->and($appliedManifest)->toContain('postgresql://postgres.larakube-plex.svc.cluster.local:5432/penpot')
        ->and($appliedManifest)->toContain('redis://redis.larakube-plex.svc.cluster.local:6379/0')
        ->and($appliedManifest)->toContain('https://files.example.com');
});

test('design:init allocates a real Commons Redis index instead of hardcoding 0', function (): void {
    // Regression guard: PENPOT_REDIS_URI used to hardcode logical DB index 0
    // directly in the Blade template, bypassing allocateCommonsRedisIndex()
    // entirely — so it was never recorded in the tenant registry and could
    // silently collide with (and get FLUSHDB'd alongside) whichever other
    // tool the registry legitimately handed index 0 to. With index 0 already
    // claimed by another tenant here, Penpot must land on a different index.
    $appliedManifest = null;
    $spec = designCommonsSpec('files.example.com');
    $registry = ['tenants' => ['outline' => ['redis_index' => 0]]];

    Process::fake(function ($process) use ($spec, $registry, &$appliedManifest) {
        $cmd = $process->command;

        if (str_contains($cmd, 'apply -f')) {
            preg_match('/apply -f (\'[^\']*\'|"[^"]*"|\S+)/', $cmd, $m);
            $path = trim($m[1] ?? '', '\'"');
            if ($path !== '' && file_exists($path) && str_contains($path, 'larakube-design-penpot')) {
                $appliedManifest = file_get_contents($path);
            }

            return Process::result(output: 'applied');
        }

        return match (true) {
            str_contains($cmd, 'get configmap plex-commons') => Process::result(output: json_encode($spec)),
            str_contains($cmd, 'get configmap plex-registry') => Process::result(output: json_encode($registry)),
            str_contains($cmd, 'S3_ACCESS_KEY') => Process::result(output: base64_encode('larakube')),
            str_contains($cmd, 'S3_SECRET_KEY') => Process::result(output: base64_encode('s3-secret')),
            str_contains($cmd, 'rollout status') => Process::result(output: 'deployment "design-penpot-backend" successfully rolled out'),
            default => Process::result(output: ''),
        };
    });

    $this->artisan(DesignInitCommand::class, [
        'environment' => 'local',
        '--admin-email' => 'admin@example.com',
        '--no-interaction' => true,
    ])->assertExitCode(0);

    expect($appliedManifest)->not->toBeNull()
        ->and($appliedManifest)->not->toContain('redis://redis.larakube-plex.svc.cluster.local:6379/0')
        ->and($appliedManifest)->toContain('redis://redis.larakube-plex.svc.cluster.local:6379/1');
});

test('design:init includes penpot-exporter container when --with-exporter flag is set', function (): void {
    $appliedManifest = null;
    fakeDesignInitProcess('files.example.com', $appliedManifest);

    $this->artisan(DesignInitCommand::class, [
        'environment' => 'local',
        '--admin-email' => 'admin@example.com',
        '--with-exporter' => true,
        '--no-interaction' => true,
    ])->assertExitCode(0);

    expect($appliedManifest)->not->toBeNull()
        ->and($appliedManifest)->toContain('penpotapp/exporter:2.17')
        ->and($appliedManifest)->toContain('PENPOT_EXPORTER_URI');
});

test('design:init errors instead of guessing when multiple instances are already registered and --domain is omitted', function (): void {
    // Regression guard for the 2026-08-17 incident: a no-flag re-run used to
    // silently derive a fresh instance slug and create a stray, conflicting
    // Deployment/Ingress alongside the real one. Now it must refuse outright
    // rather than pick one — see ResolvesToolHost::resolveInstanceAwareHost().
    $registry = [
        ['tool' => 'design', 'instance' => 'main', 'host' => 'design.example.com'],
        ['tool' => 'design', 'instance' => 'team2-example-com', 'host' => 'team2.example.com'],
    ];

    Process::fake([
        '*larakube-tools-registry*' => Process::result(output: base64_encode(json_encode($registry))),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan(DesignInitCommand::class, [
        'environment' => 'local',
        '--no-interaction' => true,
    ])->run();
})->throws(RuntimeException::class, 'pass --domain=<host>');

test('design:show displays Penpot deployment access info', function (): void {
    Process::fake([
        '*' => Process::result(output: 'installed'),
    ]);

    $this->artisan(DesignShowCommand::class, [
        'environment' => 'local',
    ])->assertExitCode(0);
});

test('design:remove cleans up Penpot resources', function (): void {
    Process::fake([
        '*' => Process::result(output: 'deleted'),
    ]);

    $this->artisan(DesignRemoveCommand::class, [
        'environment' => 'local',
        '--force' => true,
        '--no-interaction' => true,
    ])->assertExitCode(0);
});
