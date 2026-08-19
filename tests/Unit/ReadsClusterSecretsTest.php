<?php

use App\Traits\ReadsClusterSecrets;
use Illuminate\Support\Facades\Process;

function secretReader(): object
{
    return new class
    {
        use ReadsClusterSecrets;

        public function read(string $secret, string $key): ?string
        {
            return $this->readClusterSecretKey('kubectl', 'larakube-shared', $secret, $key);
        }
    };
}

test('a dotted secret key is escaped so jsonpath does not read it as a path', function (): void {
    // Unescaped, `{.data.consumers.json}` matches nothing and reads as "key
    // absent" — callers then regenerate the credential this helper exists to
    // preserve. That silently wiped every wired LiveKit consumer key.
    Process::fake(['*' => Process::result(output: base64_encode('{"chat":{}}'))]);

    expect(secretReader()->read('meet-keys', 'consumers.json'))->toBe('{"chat":{}}');

    Process::assertRan(fn ($job) => str_contains($job->command, '{.data.consumers\.json}'));
});

test('escaping is idempotent — a pre-escaped key is not double-escaped', function (): void {
    // Callers that worked around the unescaped-dot bug themselves pass
    // 'registry\.json'. Escaping that again yields 'registry\\.json', which
    // matches nothing — the same silent "key absent" failure, from the fix.
    Process::fake(['*' => Process::result(output: base64_encode('{"chat":{}}'))]);

    expect(secretReader()->read('larakube-tools-registry', 'registry\.json'))->toBe('{"chat":{}}');

    Process::assertRan(fn ($job) => str_contains($job->command, '{.data.registry\.json}')
        && ! str_contains($job->command, '\\\\.'));
});

test('an undotted key is passed through unchanged', function (): void {
    Process::fake(['*' => Process::result(output: base64_encode('hunter2'))]);

    expect(secretReader()->read('chat-secrets', 'db-password'))->toBe('hunter2');

    Process::assertRan(fn ($job) => str_contains($job->command, '{.data.db-password}'));
});

test('a missing key reads as null rather than an empty string', function (): void {
    Process::fake(['*' => Process::result(output: '')]);

    expect(secretReader()->read('meet-keys', 'consumers.json'))->toBeNull();
});
