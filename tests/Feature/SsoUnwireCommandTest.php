<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

test('sso:unwire is registered', function () {
    $this->artisan('list')
        ->assertExitCode(0)
        ->expectsOutputToContain('sso:unwire');
});

test('sso:unwire delegates to sso:wire --remove', function () {
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get deployment grafana*' => Process::result(output: 'grafana   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*sso-app-monitor*project-id*' => Process::result(output: base64_encode('proj-1')),
        '*sso-app-monitor*app-id*' => Process::result(output: base64_encode('app-1')),
        '*delete secret sso-app-monitor*' => Process::result(output: 'secret deleted'),
        '*set env deployment/grafana*' => Process::result(output: 'deployment.apps/grafana env updated'),
        '*rollout restart*' => Process::result(output: 'deployment.apps/grafana restarted'),
    ]);

    Http::fake(['*/management/v1/projects/proj-1/apps/app-1' => Http::response([], 200)]);

    $this->artisan('sso:unwire', ['--tool' => 'monitor'])
        ->assertExitCode(0)
        ->expectsOutputToContain('no longer uses Zitadel SSO');
});
