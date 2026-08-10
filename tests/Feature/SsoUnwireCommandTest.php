<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

test('sso:unwire is registered', function () {
    $this->artisan('list')
        ->assertExitCode(0)
        ->expectsOutputToContain('sso:unwire');
});

test('sso:unwire --domain= targets a specific instance instead of always the default', function () {
    // Regression test: sso:unwire had NO instance/domain targeting at all
    // before — it always unwired the tool's single default instance,
    // resolved via oidcEnv($engine) with no $instance argument. This proves
    // it now derives the instance from --domain= the same way sso:wire does.
    // Uses DATA/pocketbase because its oidcEnv() schema is the one that
    // correctly threads $instance through both 'deployment' and 'secret' —
    // Directus's schema has a separate, pre-existing (unrelated) gap where
    // its 'deployment'/'secret' keys are instance-invariant literals.
    Process::fake([
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get deployment data-pocketbase-blog-example-com*' => Process::result(output: 'data-pocketbase-blog-example-com   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*sso-app-data*project-id*' => Process::result(output: base64_encode('proj-1')),
        '*sso-app-data*app-id*' => Process::result(output: base64_encode('app-1')),
        '*delete secret sso-app-data*' => Process::result(output: 'secret deleted'),
        '*delete secret data-oidc-blog-example-com*' => Process::result(output: 'secret deleted'),
        '*set env deployment/data-pocketbase-blog-example-com*' => Process::result(output: 'env updated'),
        '*rollout restart*' => Process::result(output: 'restarted'),
    ]);

    Http::fake(['*/management/v1/projects/proj-1/apps/app-1' => Http::response([], 200)]);

    $this->artisan('sso:unwire', ['--tool' => 'data', '--engine' => 'pocketbase', '--domain' => 'blog.example.com', '--no-interaction' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('no longer uses Zitadel SSO');

    Process::assertRan(fn ($process) => str_contains($process->command, 'set env deployment/data-pocketbase-blog-example-com'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'delete secret data-oidc-blog-example-com'));
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
