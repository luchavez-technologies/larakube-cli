<?php

use App\Data\ConfigData;
use App\Enums\LaravelFeature;
use Illuminate\Support\Facades\Process;

function meetFeatureConfig(string $name = 'speeddating'): ConfigData
{
    return new ConfigData(id: 'test', name: $name);
}

test('meet is a selectable Laravel feature with a label', function (): void {
    expect(LaravelFeature::tryFrom('meet'))->toBe(LaravelFeature::MEET)
        ->and(LaravelFeature::MEET->getLabel())->toBe('Video Meetings (LiveKit)');
});

test('meet installs the LiveKit server SDK', function (): void {
    expect(LaravelFeature::MEET->getComposerDependencies())
        ->toContain('agence104/livekit-server-sdk');
});

test('meet points at the shared Meet host, not a project-scoped one', function (): void {
    // Meet is a cluster-wide tool. getServiceHost() would produce
    // meet.<project>.<tld>, which nothing serves.
    $url = LaravelFeature::MEET->getPublicEnvironmentVariables(meetFeatureConfig())['LIVEKIT_URL'];

    expect($url)->toStartWith('wss://meet.')
        ->and($url)->not->toContain('speeddating');
});

test('each project gets its own room prefix', function (): void {
    $vars = LaravelFeature::MEET->getPublicEnvironmentVariables(meetFeatureConfig());

    // OSS LiveKit cannot scope a key to a room, so this prefix is the only
    // isolation between apps sharing the SFU — and the app must enforce it.
    expect($vars['LIVEKIT_ROOM_PREFIX'])->toBe('speeddating-');
});

test('the env accessors never touch the cluster — they are called from render paths', function (): void {
    // A cluster read here would make every render and test hit kubectl. The
    // real key pair is allocated in onPostInstall() instead.
    Process::fake();

    LaravelFeature::MEET->getEnvironmentVariables(meetFeatureConfig());

    Process::assertNothingRan();
});

test('credentials are declared but left empty for onPostInstall to fill', function (): void {
    $secrets = LaravelFeature::MEET->getSecretEnvironmentVariables(meetFeatureConfig());

    expect($secrets)->toHaveKeys(['LIVEKIT_API_KEY', 'LIVEKIT_API_SECRET'])
        ->and($secrets['LIVEKIT_API_KEY'])->toBe('');
});

test('post-install guidance states both LiveKit isolation limits', function (): void {
    $lines = implode("\n", LaravelFeature::MEET->getPostInstallInstructions(meetFeatureConfig()));

    // These are the two things that will bite someone building a multi-tenant
    // app on a shared SFU, so they belong where a developer actually looks.
    expect($lines)->toContain('speeddating-')
        ->and($lines)->toContain('NOT restricted to a room')
        ->and($lines)->toContain('every event is sent to');
});

test('other features are unaffected by the meet case', function (): void {
    expect(LaravelFeature::REVERB->getComposerDependencies())->toContain('laravel/reverb')
        ->and(LaravelFeature::QUEUES->getPublicEnvironmentVariables())
        ->toBe(['QUEUE_CONNECTION' => 'database']);
});

test('adding meet to a project does not fail when Meet is not installed', function (): void {
    // `larakube add meet` must work offline / before meet:init. Falling back to
    // the declared placeholders is correct; blowing up is not.
    Process::fake([
        '*part-of=meet*' => Process::result(output: ''),
    ]);

    $method = new ReflectionMethod(LaravelFeature::MEET, 'resolveMeetCredentials');
    $method->setAccessible(true);

    expect($method->invoke(LaravelFeature::MEET, meetFeatureConfig()))->toBe([]);
});

test('when Meet is installed the project gets its own consumer key', function (): void {
    $registry = json_encode(['_system' => [
        'key' => 'LK_system', 'secret' => 's', 'roomPrefix' => 'system-', 'webhookUrl' => null,
    ]]);

    Process::fake([
        '*part-of=meet*' => Process::result(output: 'meet-livekit 1/1'),
        '*get secret meet-keys*' => Process::result(output: base64_encode($registry)),
        '*get secret larakube-tools-registry*' => Process::result(
            output: base64_encode(json_encode([['tool' => 'meet', 'instance' => '', 'host' => 'meet.example.com']])),
        ),
        '*create secret*' => Process::result(output: 'applied'),
        '*apply -f *' => Process::result(output: 'applied'),
    ]);

    $method = new ReflectionMethod(LaravelFeature::MEET, 'resolveMeetCredentials');
    $method->setAccessible(true);
    $values = $method->invoke(LaravelFeature::MEET, meetFeatureConfig());

    expect($values['LIVEKIT_API_KEY'])->toStartWith('LK_')
        ->and($values['LIVEKIT_API_SECRET'])->not->toBeEmpty()
        // The deployed host wins over the local-dev guess.
        ->and($values['LIVEKIT_URL'])->toBe('wss://meet.example.com');
});
