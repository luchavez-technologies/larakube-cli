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

test('tool:init --tool=matrix --app-name sets Element Web brand in config.json', function (): void {
    $this->artisan('tool:init --tool=matrix local --no-plex --app-name="Acme Chat" --no-interaction')
        ->assertExitCode(0);
});

test('tool:init --tool=forgejo --app-name sets FORGEJO__ui__APP_NAME', function (): void {
    $this->artisan('tool:init --tool=forgejo local --no-plex --app-name="Acme Forge" --admin-email=admin@example.com --no-interaction')
        ->assertExitCode(0);
});

test('tool:init --tool=chatwoot --app-name sets Chatwoot INSTALLATION_NAME and BRAND_NAME', function (): void {
    $this->artisan('tool:init --tool=chatwoot local --app-name="Acme Support" --admin-email=admin@example.com --no-interaction')
        ->assertExitCode(0);
});

test('tool:init --tool=glitchtip --app-name sets GlitchTip GLITCHTIP_INSTANCE_NAME', function (): void {
    $this->artisan('tool:init --tool=glitchtip local --no-plex --app-name="Acme Errors" --admin-email=admin@example.com --no-interaction')
        ->assertExitCode(0);
});

test('tool:init --tool=kutt --app-name sets Kutt SITE_NAME', function (): void {
    $this->artisan('tool:init --tool=kutt local --app-name="Acme Links" --no-interaction')
        ->assertExitCode(0);
});

test('tool:init --tool=metabase --app-name sets Metabase MB_SITE_NAME', function (): void {
    $this->artisan('tool:init --tool=metabase local --no-plex --app-name="Acme BI" --admin-email=admin@example.com --no-interaction')
        ->assertExitCode(0);
});

test('tool:init --tool=grafana --app-name sets Grafana GF_BRANDING_APP_TITLE', function (): void {
    $this->artisan('tool:init --tool=grafana local --app-name="Acme Monitor" --no-interaction')
        ->assertExitCode(0);
});
