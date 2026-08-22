<?php

use App\Traits\InteractsWithToolRegistry;
use Illuminate\Support\Facades\Process;

pest()->use(InteractsWithToolRegistry::class);

beforeEach(function (): void {
    Process::fake([
        '*get configmap plex-commons*' => json_encode([
            'version' => 1,
            'services' => [
                'postgres' => ['enabled' => true],
                'redis' => ['enabled' => true],
            ],
        ]),
        '*get configmap plex-registry*' => Process::result(output: '', exitCode: 1),
        '*create configmap plex-registry*' => Process::result(output: 'configmap created'),
        '*get secret *' => Process::result(output: '', exitCode: 1),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*create secret generic*' => Process::result(output: 'secret created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
        '*wait *' => Process::result(output: 'wait success'),
        '*exec *' => Process::result(output: 'success'),
    ]);
});

test('chat:init --app-name sets Element Web brand in config.json', function (): void {
    $this->artisan('chat:init local --no-plex --app-name="Acme Chat" --no-interaction')
        ->assertExitCode(0);
});

test('git:init --app-name sets FORGEJO__ui__APP_NAME', function (): void {
    $this->artisan('git:init local --no-plex --app-name="Acme Forge" --admin-email=admin@example.com --no-interaction')
        ->assertExitCode(0);
});

test('support:init --app-name sets Chatwoot INSTALLATION_NAME and BRAND_NAME', function (): void {
    $this->artisan('support:init local --app-name="Acme Support" --admin-email=admin@example.com --no-interaction')
        ->assertExitCode(0);
});

test('errors:init --app-name sets GlitchTip GLITCHTIP_INSTANCE_NAME', function (): void {
    $this->artisan('errors:init local --no-plex --app-name="Acme Errors" --admin-email=admin@example.com --no-interaction')
        ->assertExitCode(0);
});

test('link:init --app-name sets Kutt SITE_NAME', function (): void {
    $this->artisan('link:init local --app-name="Acme Links" --no-interaction')
        ->assertExitCode(0);
});

test('insights:init --app-name sets Metabase MB_SITE_NAME', function (): void {
    $this->artisan('insights:init local --no-plex --app-name="Acme BI" --admin-email=admin@example.com --no-interaction')
        ->assertExitCode(0);
});

test('monitor:init --app-name sets Grafana GF_BRANDING_APP_TITLE', function (): void {
    $this->artisan('monitor:init local --app-name="Acme Monitor" --no-interaction')
        ->assertExitCode(0);
});
