<?php

use App\Contracts\HasImages;
use App\Enums\ClusterTool;

test('apps LaraKube Cloud installs declare their images, and each declared image is the one their templates run', function (ClusterTool $tool): void {
    $vendor = $tool->vendor();
    expect($vendor)->toBeInstanceOf(HasImages::class);

    $templates = collect(glob(base_path('resources/views/k8s/'.$tool->templateFolder().'/*.blade.php')) ?: [])
        ->map(fn (string $file): string => (string) file_get_contents($file))
        ->implode("\n");

    foreach ($vendor->images() as $image) {
        expect($templates)->toContain($image);
    }
})->with([
    ClusterTool::POCKETBASE,
    ClusterTool::WORDPRESS,
    ClusterTool::ERPNEXT,
    ClusterTool::KUMA,
    ClusterTool::VAULTWARDEN,
    ClusterTool::OUTLINE,
    ClusterTool::UMAMI,
    ClusterTool::YOPASS,
    ClusterTool::PLANKA,
    ClusterTool::KUTT,
    ClusterTool::TEABLE,
    ClusterTool::DOCUMENSO,
    ClusterTool::RESUME,
    ClusterTool::DIRECTUS,
    ClusterTool::TWENTY,
    ClusterTool::GRAFANA,
    ClusterTool::METABASE,
    ClusterTool::GLITCHTIP,
    ClusterTool::PENPOT,
    ClusterTool::CHATWOOT,
    ClusterTool::SENDREC,
    ClusterTool::STALWART,
    ClusterTool::BULWARK,
    ClusterTool::NETBIRD,
    ClusterTool::HEADLAMP,
    ClusterTool::LIVEKIT,
]);
