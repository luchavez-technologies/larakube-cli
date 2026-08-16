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

test('getRegisteredTools parses the flat list from the cluster secret', function () {
    $trait = new class
    {
        use InteractsWithToolRegistry;

        public function get(string $kubectl)
        {
            return $this->getRegisteredTools($kubectl);
        }
    };

    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(
            base64_encode(json_encode([
                ['tool' => 'sso', 'host' => 'sso.example.com', 'instance' => 'main', 'installedAt' => '2026-08-01T09:00:00+00:00'],
            ])),
        ),
    ]);

    $tools = $trait->get('kubectl');
    expect($tools)->toBeArray()->toHaveCount(1)
        ->and($tools[0]['tool'])->toBe('sso')
        ->and($tools[0]['installedAt'])->toBe('2026-08-01T09:00:00+00:00');
});

test('getRegisteredTools returns empty array when secret is missing', function () {
    $trait = new class
    {
        use InteractsWithToolRegistry;

        public function get(string $kubectl)
        {
            return $this->getRegisteredTools($kubectl);
        }
    };

    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(''),
    ]);

    expect($trait->get('kubectl'))->toBeArray()->toBeEmpty();
});

test('findToolInstanceEntry filters the flat list by tool and instance', function () {
    $trait = new class
    {
        use InteractsWithToolRegistry;

        public function find(string $kubectl, ClusterTool $tool, string $instance)
        {
            return $this->findToolInstanceEntry($kubectl, $tool, $instance);
        }
    };

    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(
            base64_encode(json_encode([
                ['tool' => 'data', 'host' => 'data.example.com', 'instance' => 'main'],
                ['tool' => 'data', 'host' => 'blog.example.com', 'instance' => 'blog-example-com'],
                ['tool' => 'sso', 'host' => 'sso.example.com', 'instance' => 'main'],
            ])),
        ),
    ]);

    expect($trait->find('kubectl', ClusterTool::DATA, 'blog-example-com')['host'])->toBe('blog.example.com')
        ->and($trait->find('kubectl', ClusterTool::DATA, 'main')['host'])->toBe('data.example.com')
        ->and($trait->find('kubectl', ClusterTool::SSO, 'blog-example-com'))->toBeNull();
});

test('registerTool appends a new tool entry to the flat list and saves it via a temp file', function () {
    $trait = new class
    {
        use InteractsWithToolRegistry;

        public function register(string $kubectl, ClusterTool $tool)
        {
            return $this->registerTool($kubectl, $tool, ['engine' => 'zitadel']);
        }
    };

    $captured = null;

    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(''),
        '*create namespace larakube-shared*' => Process::result(),
        '*create secret generic larakube-tools-registry*' => function ($process) use (&$captured) {
            $captured = capturedRegistryWrite($process->command);

            return Process::result();
        },
    ]);

    expect($trait->register('kubectl', ClusterTool::SSO))->toBeTrue();

    Process::assertRan(fn ($process) => str_contains($process->command, 'create secret generic larakube-tools-registry')
        && str_contains($process->command, '--from-file=registry.json='));

    expect($captured)->toBeArray()->toHaveCount(1)
        ->and($captured[0]['tool'])->toBe('sso')
        ->and($captured[0]['instance'])->toBeNull()
        ->and($captured[0]['engine'])->toBe('zitadel');
});

test('registerTool merges metadata into an existing matching entry instead of duplicating it', function () {
    $trait = new class
    {
        use InteractsWithToolRegistry;

        public function register(string $kubectl, ClusterTool $tool)
        {
            return $this->registerTool($kubectl, $tool, ['host' => 'sso.new.example.com']);
        }
    };

    $captured = null;

    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(
            base64_encode(json_encode([
                ['tool' => 'sso', 'host' => 'sso.old.example.com', 'instance' => 'main', 'installedAt' => '2026-08-01T09:00:00+00:00'],
            ])),
        ),
        '*create namespace larakube-shared*' => Process::result(),
        '*create secret generic larakube-tools-registry*' => function ($process) use (&$captured) {
            $captured = capturedRegistryWrite($process->command);

            return Process::result();
        },
    ]);

    expect($trait->register('kubectl', ClusterTool::SSO))->toBeTrue();

    expect($captured)->toHaveCount(1)
        ->and($captured[0]['host'])->toBe('sso.new.example.com')
        ->and($captured[0]['installedAt'])->toBe('2026-08-01T09:00:00+00:00');
});

test('unregisterTool removes only the matching tool+instance entry and saves it', function () {
    $trait = new class
    {
        use InteractsWithToolRegistry;

        public function unregister(string $kubectl, ClusterTool $tool)
        {
            return $this->unregisterTool($kubectl, $tool);
        }
    };

    $captured = null;

    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(
            base64_encode(json_encode([
                ['tool' => 'sso', 'host' => 'sso.example.com', 'instance' => 'main'],
                ['tool' => 'data', 'host' => 'data.example.com', 'instance' => 'main'],
            ])),
        ),
        '*create namespace larakube-shared*' => Process::result(),
        '*create secret generic larakube-tools-registry*' => function ($process) use (&$captured) {
            $captured = capturedRegistryWrite($process->command);

            return Process::result();
        },
    ]);

    expect($trait->unregister('kubectl', ClusterTool::SSO))->toBeTrue();

    expect($captured)->toHaveCount(1)
        ->and($captured[0]['tool'])->toBe('data');
});

test('two different tools coexist in the same flat list without colliding', function () {
    $trait = new class
    {
        use InteractsWithToolRegistry;

        public function all(string $kubectl, ClusterTool $tool)
        {
            return $this->getToolInstances($kubectl, $tool);
        }
    };

    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(
            base64_encode(json_encode([
                ['tool' => 'data', 'host' => 'data.example.com', 'instance' => 'main'],
                ['tool' => 'data', 'host' => 'blog.example.com', 'instance' => 'blog-example-com'],
                ['tool' => 'sso', 'host' => 'sso.example.com', 'instance' => 'main'],
            ])),
        ),
    ]);

    expect($trait->all('kubectl', ClusterTool::DATA))->toBe(['main', 'blog-example-com'])
        ->and($trait->all('kubectl', ClusterTool::SSO))->toBe(['main']);
});
