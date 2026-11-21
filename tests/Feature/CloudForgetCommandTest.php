<?php

use App\Data\GlobalConfigData;
use App\Data\StackData;
use App\Exceptions\MissingFlagException;
use App\Http\Integrations\Cloudflare\Requests\DeleteR2ObjectRequest;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

function cloudForgetRegister(): void
{
    $config = GlobalConfigData::load();
    $config->putStack(new StackData(
        name: 'old-box',
        provider: 'gcp',
        region: 'asia-southeast1',
        context: 'larakube-203.0.113.40',
        ip: '203.0.113.40',
        account: 'ops@example.com',
        projectId: 'demo-project-123',
    ));
    $config->save();

    @mkdir(home_path('.larakube/tofu/old-box'), 0700, true);
    file_put_contents(home_path('.larakube/tofu/old-box/terraform.tfstate'), '{}');
}

test('cloud:forget removes the server from LaraKube and never runs OpenTofu or a cloud CLI', function (): void {
    cloudForgetRegister();
    Process::fake(['*' => Process::result(output: '')]);

    $this->artisan('cloud:forget old-box --force')
        ->expectsOutputToContain('Nothing is deleted at the provider')
        ->expectsOutputToContain('demo-project-123')
        ->expectsOutputToContain("Forgot 'old-box'.")
        ->assertExitCode(0);

    expect(GlobalConfigData::load()->findStack('old-box'))->toBeNull()
        ->and(home_path('.larakube/tofu/old-box'))->not->toBeDirectory();
    Process::assertNotRan(fn ($p): bool => (bool) preg_match('/\b(tofu|terraform|gcloud)\b.*(destroy|delete|apply)/', implode(' ', (array) $p->command)));
});

test('cloud:forget names a stack it does not know', function (): void {
    Process::fake(['*' => Process::result(output: '')]);

    $this->artisan('cloud:forget nope --force')
        ->expectsOutputToContain("No registered stack or unfinished setup named 'nope'.")
        ->assertExitCode(1);
});

test('cloud:forget needs --force when it cannot ask, and forgets nothing', function (): void {
    cloudForgetRegister();
    Process::fake(['*' => Process::result(output: '')]);

    try {
        $this->artisan('cloud:forget old-box --no-interaction');
    } finally {
        expect(GlobalConfigData::load()->findStack('old-box'))->not->toBeNull();
    }
})->throws(MissingFlagException::class, 'Missing required --force');

function cloudForgetUseR2(): void
{
    $config = GlobalConfigData::load();
    $config->tofuStateEnabled = true;
    $config->tofuStateBucket = 'larakube-tofu-state';
    $config->tofuStateEndpoint = 'https://account.r2.cloudflarestorage.com';
    $config->tofuStateAccountId = 'acct123';
    $config->tofuStateApiToken = 'cf-token';
    $config->save();
}

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('forgetting a server deletes its state in R2 too, so its name can be reused', function (): void {
    cloudForgetRegister();
    cloudForgetUseR2();
    Process::fake(['*' => Process::result(output: '')]);
    Saloon::fake([DeleteR2ObjectRequest::class => MockResponse::make(['success' => true])]);

    $this->artisan('cloud:forget old-box --force')->assertExitCode(0);

    Saloon::assertSent(fn ($request): bool => $request instanceof DeleteR2ObjectRequest
        && $request->resolveEndpoint() === 'client/v4/accounts/acct123/r2/buckets/larakube-tofu-state/objects/tofu-state/old-box/terraform.tfstate');
});

test('when the state in R2 cannot be deleted, forgetting still finishes and names the object to delete by hand', function (): void {
    cloudForgetRegister();
    cloudForgetUseR2();
    Process::fake(['*' => Process::result(output: '')]);
    Saloon::fake([DeleteR2ObjectRequest::class => MockResponse::make(['success' => false], 403)]);

    $this->artisan('cloud:forget old-box --force')
        ->expectsOutputToContain('larakube-tofu-state/tofu-state/old-box/terraform.tfstate')
        ->assertExitCode(0);

    expect(GlobalConfigData::load()->findStack('old-box'))->toBeNull();
});

test('cloud:forget also clears an unfinished setup, which destroy cannot read', function (): void {
    cloudForgetUseR2();
    $config = GlobalConfigData::load();
    $config->tofuPassphrases['half-made'] = str_repeat('p', 32);
    $config->save();
    @mkdir(home_path('.larakube/tofu/half-made'), 0700, true);
    file_put_contents(home_path('.larakube/tofu/half-made/main.tf'), 'provider "google" {}');
    Process::fake(['*' => Process::result(output: '')]);
    Saloon::fake([DeleteR2ObjectRequest::class => MockResponse::make(['success' => true])]);

    $this->artisan('cloud:forget half-made --force')
        ->expectsOutputToContain("Forgot 'half-made'.")
        ->assertExitCode(0);

    expect(home_path('.larakube/tofu/half-made'))->not->toBeDirectory()
        ->and(GlobalConfigData::load()->tofuPassphrases)->not->toHaveKey('half-made');
    Saloon::assertSent(fn ($request): bool => $request instanceof DeleteR2ObjectRequest
        && str_contains($request->resolveEndpoint(), 'tofu-state/half-made/terraform.tfstate'));
});
