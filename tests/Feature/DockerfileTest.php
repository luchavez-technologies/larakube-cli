<?php

use App\Data\ConfigData;

function renderDockerfile(array $overrides = []): string
{
    $config = ConfigData::from(array_merge([
        'name' => 'docktest',
        'serverVariation' => 'frankenphp',
        'phpVersion' => '8.4',
        'os' => 'alpine',
    ], $overrides));

    return view('docker.php', ['config' => $config])->render();
}

test('Node is always installed in the development stage, regardless of SSR', function () {
    foreach ([[], ['features' => ['ssr']], ['features' => ['horizon', 'queues']]] as $overrides) {
        $dockerfile = renderDockerfile($overrides);

        // The local Vite/HMR pod runs `npm run dev` from this stage.
        $devSection = substr(
            $dockerfile,
            strpos($dockerfile, 'AS development'),
            strpos($dockerfile, 'AS ci') - strpos($dockerfile, 'AS development'),
        );

        expect($devSection)->toContain('apk add --no-cache nodejs npm');
    }
});

test('Node is included in the deploy stage only when SSR is enabled', function () {
    $deploySection = fn (string $d) => substr($d, strpos($d, 'AS deploy'));

    // SSR → deploy runs `node bootstrap/ssr/ssr.js`, so Node is required.
    expect($deploySection(renderDockerfile(['features' => ['ssr']])))
        ->toContain('apk add --no-cache nodejs npm');

    // No SSR → deploy serves pre-built static assets, so Node is omitted.
    expect($deploySection(renderDockerfile(['features' => ['horizon', 'queues']])))
        ->not->toContain('nodejs npm');
});

test('Node is no longer baked into the base stage', function () {
    foreach ([['features' => ['ssr']], ['features' => ['horizon']]] as $overrides) {
        $dockerfile = renderDockerfile($overrides);
        $baseSection = substr($dockerfile, 0, strpos($dockerfile, 'AS development'));

        expect($baseSection)->not->toContain('nodejs npm');
    }
});
