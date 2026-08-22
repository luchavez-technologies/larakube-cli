<?php

use App\Exceptions\MissingFlagException;
use Illuminate\Support\Facades\Process;

function meetWireFakes(array $overrides = []): array
{
    $registry = json_encode(['_system' => [
        'key' => 'LK_system', 'secret' => 'systemsecret', 'roomPrefix' => 'system-', 'webhookUrl' => null,
    ]]);

    $homeserver = base64_encode("server_name: \"chat.example.com\"\nreport_stats: false\n");

    return array_merge([
        '*part-of=meet*' => Process::result(output: 'meet-livekit 1/1'),
        '*get deployment chat-synapse*' => Process::result(output: 'chat-synapse 1/1'),
        '*get deployment meet-lk-jwt*' => Process::result(output: ''),
        '*get secret meet-keys*' => Process::result(output: base64_encode($registry)),
        '*get secret larakube-tools-registry*' => Process::result(output: base64_encode(json_encode([
            ['tool' => 'meet', 'host' => 'meet.example.com', 'instance' => 'main'],
            ['tool' => 'chat', 'host' => 'chat.example.com', 'instance' => 'main'],
        ]))),
        '*get secret chat-synapse-config*' => Process::result(output: $homeserver),
        '*create secret*' => Process::result(output: 'secret created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'restarted'),
        '*delete *' => Process::result(output: 'deleted'),
    ], $overrides);
}

test('meet:wire refuses when Meet is not installed instead of half-wiring chat', function (): void {
    Process::fake(meetWireFakes(['*part-of=meet*' => Process::result(output: '')]));

    $this->artisan('meet:wire local --tool=chat --no-interaction')
        ->assertExitCode(1)
        ->expectsOutputToContain('Meet is not installed');
});

test('meet:wire refuses when Team Chat is not installed', function (): void {
    Process::fake(meetWireFakes(['*get deployment chat-synapse*' => Process::result(output: '')]));

    $this->artisan('meet:wire local --tool=chat --no-interaction')
        ->assertExitCode(1)
        ->expectsOutputToContain('is not installed on this cluster');
});

test('meet:wire rejects a tool that cannot be wired to Meet', function (): void {
    Process::fake(meetWireFakes());

    $this->artisan('meet:wire local --tool=notes --no-interaction')
        ->assertExitCode(1)
        ->expectsOutputToContain('cannot be wired to Meet');
});

test('meet:wire demands --tool by name when it cannot prompt', function (): void {
    // Every other wire command does this; a silent default would pick chat and
    // become wrong the moment a second tool is wireable.
    Process::fake(meetWireFakes());

    $this->artisan('meet:wire local --no-interaction')->run();
})->throws(MissingFlagException::class, 'Missing required --tool');

test('meet:wire deploys the bridge and points Synapse at it', function (): void {
    Process::fake(meetWireFakes());

    $this->artisan('meet:wire local --tool=chat --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('Team Chat is wired to Meet.')
        ->expectsOutputToContain('https://meet.example.com/jwt');

    // The wiring must be recorded so a later chat:init re-render does not
    // silently drop calling.
    Process::assertRan(fn ($job) => str_contains($job->command, 'create secret generic chat-meet'));
    Process::assertRan(fn ($job) => str_contains($job->command, 'rollout restart deployment/chat-synapse'));
});

test('meet:unwire is a no-op when chat was never wired', function (): void {
    Process::fake(meetWireFakes());

    $this->artisan('meet:unwire local --tool=chat --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('not wired to Meet');
});

test('meet:unwire removes the bridge and revokes the key', function (): void {
    $registry = json_encode([
        '_system' => ['key' => 'LK_system', 'secret' => 's1', 'roomPrefix' => 'system-', 'webhookUrl' => null],
        'chat' => ['key' => 'LK_chat', 'secret' => 's2', 'roomPrefix' => 'matrix-', 'webhookUrl' => null],
    ]);

    Process::fake(meetWireFakes([
        '*get secret meet-keys*' => Process::result(output: base64_encode($registry)),
        '*get deployment meet-lk-jwt*' => Process::result(output: 'meet-lk-jwt 1/1'),
    ]));

    $this->artisan('meet:unwire local --tool=chat --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('disconnected from Meet');

    Process::assertRan(fn ($job) => str_contains($job->command, 'deployment/meet-lk-jwt'));
    Process::assertRan(fn ($job) => str_contains($job->command, 'delete secret chat-meet'));
});
