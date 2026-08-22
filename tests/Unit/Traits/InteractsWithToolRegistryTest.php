<?php

use App\Enums\ClusterTool;
use App\Traits\InteractsWithToolRegistry;
use Illuminate\Support\Facades\Process;

/** Extract the JSON body a `saveToolRegistry()` write handed to `--from-file=registry.json=<tmpfile>` before the trait unlinks it. */
function capturedRegistryWrite(string $command): ?array
{
    if (! preg_match('/--from-file=registry\.json=(\S+)/', $command, $m)) {
        return null;
    }

    return json_decode(file_get_contents($m[1]), true);
}

function toolRegistryHarness(): object
{
    return new class
    {
        use InteractsWithToolRegistry;

        public function get(string $kubectl)
        {
            return $this->getRegisteredTools($kubectl);
        }

        public function find(string $kubectl, ClusterTool $tool, ?string $instance = null)
        {
            return $this->findToolInstanceEntry($kubectl, $tool, $instance);
        }

        public function register(string $kubectl, ClusterTool $tool, array $metadata = [], ?string $instance = null)
        {
            return $this->registerTool($kubectl, $tool, $metadata, $instance);
        }

        public function unregister(string $kubectl, ClusterTool $tool, ?string $instance = null)
        {
            return $this->unregisterTool($kubectl, $tool, $instance);
        }

        public function all(string $kubectl, ClusterTool $tool)
        {
            return $this->getToolInstances($kubectl, $tool);
        }
    };
}

test('getRegisteredTools parses the flat list from the cluster secret', function (): void {
    $trait = toolRegistryHarness();

    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(
            base64_encode(json_encode([
                ['tool' => 'sso', 'host' => 'sso.example.com', 'instance' => 'sso-example-com', 'installedAt' => '2026-08-01T09:00:00+00:00'],
            ])),
        ),
    ]);

    $tools = $trait->get('kubectl');
    expect($tools)->toBeArray()->toHaveCount(1)
        ->and($tools[0]['tool'])->toBe('sso')
        ->and($tools[0]['installedAt'])->toBe('2026-08-01T09:00:00+00:00');
});

test('getRegisteredTools returns empty array when secret is missing', function (): void {
    $trait = toolRegistryHarness();

    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(''),
    ]);

    expect($trait->get('kubectl'))->toBeArray()->toBeEmpty();
});

test('findToolInstanceEntry does an exact match when an instance is given', function (): void {
    $trait = toolRegistryHarness();

    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(
            base64_encode(json_encode([
                ['tool' => 'data', 'host' => 'data.example.com', 'instance' => 'data-example-com'],
                ['tool' => 'data', 'host' => 'blog.example.com', 'instance' => 'blog-example-com'],
                ['tool' => 'sso', 'host' => 'sso.example.com', 'instance' => 'sso-example-com'],
            ])),
        ),
    ]);

    expect($trait->find('kubectl', ClusterTool::DATA, 'blog-example-com')['host'])->toBe('blog.example.com')
        ->and($trait->find('kubectl', ClusterTool::DATA, 'data-example-com')['host'])->toBe('data.example.com')
        ->and($trait->find('kubectl', ClusterTool::SSO, 'blog-example-com'))->toBeNull();
});

test('findToolInstanceEntry with no instance resolves the tool\'s sole entry regardless of its stored value', function (): void {
    $trait = toolRegistryHarness();

    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(
            base64_encode(json_encode([
                // A legacy stale value ('main') and a real derived slug both
                // resolve the same way when $instance is omitted — there is
                // no special stored value being recognized, just "there's
                // exactly one entry for this tool."
                ['tool' => 'sso', 'host' => 'sso.example.com', 'instance' => 'main'],
                ['tool' => 'data', 'host' => 'data.example.com', 'instance' => 'data-example-com'],
            ])),
        ),
    ]);

    expect($trait->find('kubectl', ClusterTool::SSO)['host'])->toBe('sso.example.com')
        ->and($trait->find('kubectl', ClusterTool::DATA)['host'])->toBe('data.example.com');
});

test('findToolInstanceEntry with no instance refuses to guess when 2+ entries exist', function (): void {
    $trait = toolRegistryHarness();

    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(
            base64_encode(json_encode([
                ['tool' => 'notes', 'host' => 'notes.example.com', 'instance' => 'notes-example-com'],
                ['tool' => 'notes', 'host' => 'blog.example.com', 'instance' => 'blog-example-com'],
            ])),
        ),
    ]);

    expect($trait->find('kubectl', ClusterTool::NOTES))->toBeNull();
});

test('registerTool appends a new tool entry to the flat list and saves it via a temp file', function (): void {
    $trait = toolRegistryHarness();
    $captured = null;

    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(''),
        '*create namespace larakube-shared*' => Process::result(),
        '*create secret generic larakube-tools-registry*' => function ($process) use (&$captured) {
            $captured = capturedRegistryWrite($process->command);

            return Process::result();
        },
    ]);

    expect($trait->register('kubectl', ClusterTool::SSO, ['engine' => 'zitadel']))->toBeTrue();

    Process::assertRan(fn ($process) => str_contains($process->command, 'create secret generic larakube-tools-registry')
        && str_contains($process->command, '--from-file=registry.json='));

    expect($captured)->toBeArray()->toHaveCount(1)
        ->and($captured[0]['tool'])->toBe('sso')
        ->and($captured[0]['instance'])->toBeNull()
        ->and($captured[0]['engine'])->toBe('zitadel');
});

test('registerTool merges metadata into an existing matching entry instead of duplicating it', function (): void {
    $trait = toolRegistryHarness();
    $captured = null;

    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(
            base64_encode(json_encode([
                ['tool' => 'sso', 'host' => 'sso.old.example.com', 'instance' => 'sso-old-example-com', 'installedAt' => '2026-08-01T09:00:00+00:00'],
            ])),
        ),
        '*create namespace larakube-shared*' => Process::result(),
        '*create secret generic larakube-tools-registry*' => function ($process) use (&$captured) {
            $captured = capturedRegistryWrite($process->command);

            return Process::result();
        },
    ]);

    expect($trait->register('kubectl', ClusterTool::SSO, ['host' => 'sso.new.example.com']))->toBeTrue()
        ->and($captured)->toHaveCount(1)
        ->and($captured[0]['host'])->toBe('sso.new.example.com')
        ->and($captured[0]['installedAt'])->toBe('2026-08-01T09:00:00+00:00');
});

test('registerTool self-heals a stale stored instance value in place, never appending a duplicate', function (): void {
    $trait = toolRegistryHarness();
    $captured = null;

    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(
            base64_encode(json_encode([
                // A leftover pre-migration value — nothing textually matches
                // the freshly-derived slug below, but there's exactly one
                // entry for this tool, so it's still found and corrected.
                ['tool' => 'sso', 'host' => 'sso.example.com', 'instance' => 'main'],
            ])),
        ),
        '*create namespace larakube-shared*' => Process::result(),
        '*create secret generic larakube-tools-registry*' => function ($process) use (&$captured) {
            $captured = capturedRegistryWrite($process->command);

            return Process::result();
        },
    ]);

    expect($trait->register('kubectl', ClusterTool::SSO, [], 'sso-example-com'))->toBeTrue()
        ->and($captured)->toHaveCount(1)
        ->and($captured[0]['instance'])->toBe('sso-example-com');
});

test('unregisterTool removes only the matching tool entry and saves it', function (): void {
    $trait = toolRegistryHarness();
    $captured = null;

    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(
            base64_encode(json_encode([
                ['tool' => 'sso', 'host' => 'sso.example.com', 'instance' => 'sso-example-com'],
                ['tool' => 'data', 'host' => 'data.example.com', 'instance' => 'data-example-com'],
            ])),
        ),
        '*create namespace larakube-shared*' => Process::result(),
        '*create secret generic larakube-tools-registry*' => function ($process) use (&$captured) {
            $captured = capturedRegistryWrite($process->command);

            return Process::result();
        },
    ]);

    expect($trait->unregister('kubectl', ClusterTool::SSO))->toBeTrue()
        ->and($captured)->toHaveCount(1)
        ->and($captured[0]['tool'])->toBe('data');
});

test('two different tools coexist in the same flat list without colliding', function (): void {
    $trait = toolRegistryHarness();

    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(
            base64_encode(json_encode([
                ['tool' => 'data', 'host' => 'data.example.com', 'instance' => 'data-example-com'],
                ['tool' => 'data', 'host' => 'blog.example.com', 'instance' => 'blog-example-com'],
                ['tool' => 'sso', 'host' => 'sso.example.com', 'instance' => 'sso-example-com'],
            ])),
        ),
    ]);

    expect($trait->all('kubectl', ClusterTool::DATA))->toBe(['data-example-com', 'blog-example-com'])
        ->and($trait->all('kubectl', ClusterTool::SSO))->toBe(['sso-example-com']);
});
