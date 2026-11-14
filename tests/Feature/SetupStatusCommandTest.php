<?php

use App\Enums\CliTool;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

/**
 * @return array<string, mixed>
 */
function setupStatusJson(string $arguments = ''): array
{
    Artisan::call(trim("setup:status {$arguments} --json"));

    return json_decode(trim(Artisan::output()), true);
}

test('setup:status reports every tool setup manages, with where it lives and who owns it', function (): void {
    Process::fake([
        'command -v kubectl' => Process::result('/opt/homebrew/bin/kubectl'),
        '*kubectl*version*--client*' => Process::result("Client Version: v1.34.1\nKustomize Version: v5"),
        '*' => Process::result('', exitCode: 1),
    ]);

    $payload = setupStatusJson();
    $tools = collect($payload['tools'])->keyBy('slug');

    expect($payload['success'])->toBeTrue()
        ->and($payload['binDir'])->toBe(CliTool::binDir())
        ->and($tools->keys()->all())->toBe(array_map(fn (CliTool $tool): string => $tool->value, CliTool::cases()))
        ->and($tools['kubectl'])->toMatchArray([
            'installed' => true,
            'path' => '/opt/homebrew/bin/kubectl',
            'channel' => 'homebrew',
            'managed' => true,
            'version' => 'Client Version: v1.34.1',
            'default' => true,
        ])
        ->and($tools['hcloud'])->toMatchArray(['installed' => false, 'path' => null, 'channel' => null, 'version' => null]);
});

test('setup:status can report a single tool', function (): void {
    Process::fake([
        'command -v aws' => Process::result('/usr/bin/aws'),
        '*' => Process::result('', exitCode: 1),
    ]);

    $payload = setupStatusJson('aws');

    expect($payload['tools'])->toHaveCount(1)
        ->and($payload['tools'][0])->toMatchArray(['slug' => 'aws', 'channel' => 'system', 'managed' => false]);
});

test('setup:status rejects an unknown tool', function (): void {
    Process::fake();

    $exit = Artisan::call('setup:status nope --json');
    $payload = json_decode(trim(Artisan::output()), true);

    expect($exit)->toBe(1)
        ->and($payload['success'])->toBeFalse()
        ->and($payload['error'])->toContain("Unknown tool 'nope'");
});
