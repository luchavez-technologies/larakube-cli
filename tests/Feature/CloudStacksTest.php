<?php

use App\Data\GlobalConfigData;
use App\Data\StackData;
use Illuminate\Support\Facades\Artisan;

test('cloud:stacks runs cleanly and outputs registered stacks', function (): void {
    $this->artisan('cloud:stacks')
        ->assertExitCode(0);
});

test('StackData stores and preserves provider correctly', function (): void {
    $config = new GlobalConfigData;
    $doStack = new StackData(
        name: 'do-vps',
        provider: 'do',
        kind: 'vps',
        region: 'sgp1',
        ip: '1.2.3.4',
    );
    $gcpStack = new StackData(
        name: 'gcp-vps',
        provider: 'gcp',
        kind: 'vps',
        region: 'us-central1',
        ip: '34.1.2.3',
    );

    $config->putStack($doStack);
    $config->putStack($gcpStack);

    $stacks = $config->getStacks();
    expect($stacks)->toHaveCount(2)
        ->and($stacks['do-vps']->provider)->toBe('do')
        ->and($stacks['gcp-vps']->provider)->toBe('gcp')
        ->and($stacks['gcp-vps']->region)->toBe('us-central1')
        ->and($stacks['gcp-vps']->ip)->toBe('34.1.2.3');
});

test('cloud:stacks --json lists registered stacks with a status, plus unfinished setups', function (): void {
    $config = GlobalConfigData::load();
    $config->putStack(new StackData(name: 'workshop-demo', provider: 'gcp', kind: 'vps', region: 'asia-east1', ip: '203.0.113.21', context: 'larakube-203.0.113.21'));
    $config->putStack(new StackData(name: 'half-done', provider: 'do', kind: 'vps', region: 'sgp1', ip: '203.0.113.40'));
    $config->save();

    $unfinishedDir = home_path('.larakube/tofu/cancel-test');
    mkdir($unfinishedDir, 0755, true);
    file_put_contents($unfinishedDir.'/main.tf', "provider \"google\" {\n  region = \"asia-northeast1\"\n}\n");

    Artisan::call('cloud:stacks', ['--json' => true]);
    $decoded = json_decode(trim(Artisan::output()), true);
    $byName = collect($decoded['stacks'])->keyBy('name');

    expect($decoded['success'])->toBeTrue()
        ->and($byName->keys()->sort()->values()->all())->toBe(['cancel-test', 'half-done', 'workshop-demo'])
        ->and($byName['workshop-demo'])->toMatchArray(['status' => 'ready', 'provider' => 'gcp', 'ip' => '203.0.113.21'])
        ->and($byName['half-done']['status'])->toBe('incomplete')
        ->and($byName['cancel-test'])->toMatchArray(['status' => 'unfinished', 'provider' => 'gcp', 'region' => 'asia-northeast1']);
});

test('cloud:stacks --json with nothing registered is an empty list, not a message', function (): void {
    Artisan::call('cloud:stacks', ['--json' => true]);

    expect(json_decode(trim(Artisan::output()), true))->toBe(['success' => true, 'stacks' => []]);
});

test('cloud:stacks --json tells a dev box from a deploy server, and a dev box with a key is ready without a context', function (): void {
    $config = GlobalConfigData::load();
    $config->putStack(new StackData(name: 'workshop-demo', provider: 'gcp', kind: 'vps', region: 'asia-east1', ip: '203.0.113.21', context: 'larakube-203.0.113.21'));
    $config->putStack(new StackData(name: 'my-dev-box', provider: 'gcp', kind: 'vps', region: 'us-central1', ip: '203.0.113.50', sshKey: '/home/me/.ssh/id_ed25519', role: 'dev'));
    $config->putStack(new StackData(name: 'dev-no-key', provider: 'gcp', kind: 'vps', region: 'us-central1', ip: '203.0.113.51', role: 'dev'));
    $config->save();

    Artisan::call('cloud:stacks', ['--json' => true]);
    $byName = collect(json_decode(trim(Artisan::output()), true)['stacks'])->keyBy('name');

    expect($byName['workshop-demo'])->toMatchArray(['role' => 'deploy', 'status' => 'ready'])
        ->and($byName['my-dev-box'])->toMatchArray(['role' => 'dev', 'status' => 'ready', 'context' => null])
        ->and($byName['dev-no-key']['status'])->toBe('incomplete');
});
