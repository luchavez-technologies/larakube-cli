<?php

use App\Enums\ClusterTool;

test('each Flow engine names its resources per instance through ToolInstance', function (): void {
    $n8n = App\Data\ToolInstance::forHost(ClusterTool::N8N, 'flow.example.com', 'n8n');
    $windmill = App\Data\ToolInstance::forHost(ClusterTool::WINDMILL, 'jobs.example.com', 'windmill');

    expect($n8n->deployment())->toBe('n8n-flow-example-com')
        ->and($n8n->secret())->toBe('n8n-secrets-flow-example-com')
        ->and($n8n->volume())->toBe('n8n-storage-flow-example-com')
        ->and($n8n->database())->toBe('n8n_flow_example_com')
        ->and($n8n->vpnMiddleware()?->name)->toBe('n8n-vpn-only-flow-example-com')
        ->and(ClusterTool::N8N->smtpEnv('n8n', $n8n->instance))->toMatchArray([
            'deployment' => 'n8n-flow-example-com',
            'secret' => 'n8n-smtp-flow-example-com',
        ])
        ->and($windmill->deployment())->toBe('windmill-jobs-example-com')
        ->and($windmill->database())->toBe('windmill_jobs_example_com')
        ->and($windmill->vpnMiddleware()?->name)->toBe('windmill-vpn-only-jobs-example-com');
});

test('Flow templates take every image from the engine class, never a literal tag', function (string $template): void {
    $source = (string) file_get_contents(base_path("resources/views/k8s/{$template}/shared.blade.php"));

    preg_match_all('/^\s*image:\s*(.+)$/m', $source, $m);

    expect($m[1])->not->toBeEmpty()
        ->and(array_filter($m[1], fn (string $image) => ! str_starts_with(trim($image), '{{ $tool->image(')))->toBeEmpty();
})->with(['n8n', 'windmill']);

test('n8n sets fsGroup so a freshly provisioned PVC is writable by its non-root user', function (): void {
    // A fresh PVC on most CSI drivers (GKE's pd.csi.storage.gke.io included)
    // is root-owned by default. n8n's image runs as the unprivileged 'node'
    // user (UID/GID 1000) and writes its instance config to the mounted
    // volume at startup — without fsGroup it fails with
    // "EACCES: permission denied, open '/home/node/.n8n/config'" and the
    // pod never becomes ready. Confirmed live on a GKE cluster.
    $source = (string) file_get_contents(base_path('resources/views/k8s/n8n/shared.blade.php'));

    expect($source)->toContain('fsGroup: 1000');
});

test('every Flow engine pins every image it declares to an explicit version', function (): void {
    foreach (App\Enums\FlowTool::cases() as $engine) {
        foreach ($engine->tool()->images() as $key => $image) {
            expect($image)->toMatch('/:[0-9][^:\/]*$/', "{$engine->value}.{$key} is not pinned to a version");
        }
    }
});
