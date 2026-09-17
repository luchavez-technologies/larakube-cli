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

test('a WordPress build context leaves local uploads out of the image', function (): void {
    $ignore = fn (array $overrides): string => view('docker.ignore', ['config' => ConfigData::from(array_merge([
        'name' => 'docktest',
        'serverVariation' => 'fpm-nginx',
        'phpVersion' => '8.4',
        'os' => 'alpine',
    ], $overrides))])->render();

    expect($ignore(['framework' => 'wordpress']))->toContain('web/app/uploads/*')
        ->and($ignore([]))->not->toContain('web/app/uploads');
});

test('a WordPress image has no npm assets stage and owns web/app/uploads instead of Laravel storage', function (): void {
    $wordpress = renderDockerfile(['framework' => 'wordpress', 'serverVariation' => 'fpm-nginx']);
    $deploy = substr($wordpress, strpos($wordpress, 'AS deploy'));
    $laravel = renderDockerfile();

    expect($wordpress)->not->toContain('AS assets')
        ->not->toContain('npm ci')
        ->not->toContain('--from=assets')
        ->and($deploy)->toContain('mkdir -p web/app/uploads')
        ->not->toContain('storage bootstrap/cache')
        ->and($laravel)->toContain('AS assets')
        ->toContain('--from=assets')
        ->toContain('mkdir -p storage bootstrap/cache');
});

test('Node is always installed in the development stage, regardless of SSR', function (): void {
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

test('Node is included in the deploy stage only when SSR is enabled', function (): void {
    $deploySection = fn (string $d) => substr($d, strpos($d, 'AS deploy'));

    // SSR → deploy runs `node bootstrap/ssr/ssr.js`, so Node is required.
    expect($deploySection(renderDockerfile(['features' => ['ssr']])))
        ->toContain('apk add --no-cache nodejs npm');

    // No SSR → deploy serves pre-built static assets, so Node is omitted.
    expect($deploySection(renderDockerfile(['features' => ['horizon', 'queues']])))
        ->not->toContain('nodejs npm');
});

test('Node is no longer baked into the base stage', function (): void {
    foreach ([['features' => ['ssr']], ['features' => ['horizon']]] as $overrides) {
        $dockerfile = renderDockerfile($overrides);
        $baseSection = substr($dockerfile, 0, strpos($dockerfile, 'AS development'));

        expect($baseSection)->not->toContain('nodejs npm');
    }
});

test('vendor stays in every PHP build context, and Node images never take the host node_modules', function (): void {
    $ignore = fn (array $data): string => view('docker.ignore', ['config' => ConfigData::from(array_merge(['name' => 'shop'], $data))])->render();
    $lines = fn (string $content): array => array_map('trim', explode("\n", $content));

    expect($lines($ignore(['githubActions' => false])))->not->toContain('vendor')
        ->and($lines($ignore(['githubActions' => true])))->not->toContain('vendor')
        ->and($lines($ignore(['framework' => 'astro'])))->toContain('node_modules')->toContain('dist')
        ->and($lines($ignore(['framework' => 'nextjs'])))->toContain('node_modules')
        ->and($ignore(['framework' => 'astro']))->not->toContain('storage/framework');
});
