<?php

use App\Data\GlobalConfigData;
use App\Data\StackData;
use App\Exceptions\MissingFlagException;
use Illuminate\Support\Facades\Process;

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
        ->and(is_dir(home_path('.larakube/tofu/old-box')))->toBeFalse();
    Process::assertNotRan(fn ($p): bool => (bool) preg_match('/\b(tofu|terraform|gcloud)\b.*(destroy|delete|apply)/', implode(' ', (array) $p->command)));
});

test('cloud:forget names a stack it does not know', function (): void {
    Process::fake(['*' => Process::result(output: '')]);

    $this->artisan('cloud:forget nope --force')
        ->expectsOutputToContain("No registered stack named 'nope'.")
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
