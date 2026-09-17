<?php

use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use App\Traits\InteractsWithToolRegistry;
use App\Traits\ResolvesToolHost;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;

/**
 * Registry rows are keyed by the host-derived instance slug, but a cloud
 * `*:init` asks for its host before that slug exists. These pin how the two
 * meet: an existing row is found without re-prompting, and a freshly prompted
 * host lands on the slug row instead of an instance-less one.
 */
function resolvesToolHostRunner(): object
{
    return new class
    {
        use InteractsWithToolRegistry, ResolvesToolHost;

        public function host(string $env): string
        {
            return $this->resolveToolHost(SharedClusterService::FORGEJO, ClusterTool::GIT, $env, 'kubectl');
        }

        public function option(string $key): mixed
        {
            return null;
        }

        protected function laraKubeInfo(string $message): void {}

        protected function resolveProjectConfig(): ?App\Data\ConfigData
        {
            return null;
        }
    };
}

function resolvesToolHostRegistry(array $rows): array
{
    return ['*get secret larakube-tools-registry*' => base64_encode(json_encode($rows))];
}

test('a tool recorded under its host-derived slug is found without asking for the host again', function (): void {
    Process::fake(resolvesToolHostRegistry([
        ['tool' => 'git', 'instance' => 'git-example-com', 'host' => 'git.example.com'],
    ]));

    expect(resolvesToolHostRunner()->host('production'))->toBe('git.example.com');
});

test('several different recorded hosts are ambiguous, so the host is asked for', function (): void {
    Prompt::fake(['g', 'i', 't', '.', 'n', 'e', 'w', Laravel\Prompts\Key::ENTER]);
    Process::fake([
        ...resolvesToolHostRegistry([
            ['tool' => 'git', 'instance' => 'git-one-example', 'host' => 'git.one.example'],
            ['tool' => 'git', 'instance' => 'git-two-example', 'host' => 'git.two.example'],
        ]),
        '*' => Process::result(),
    ]);

    expect(resolvesToolHostRunner()->host('production'))->toBe('git.new');
});

test('a prompted host is recorded on its host-derived slug, never an instance-less row', function (): void {
    $saved = null;
    Prompt::fake(['g', 'i', 't', '.', 'e', 'x', 'a', 'm', 'p', 'l', 'e', '.', 'c', 'o', 'm', Laravel\Prompts\Key::ENTER]);
    Process::fake([
        '*get secret larakube-tools-registry*' => '',
        '*create secret generic larakube-tools-registry*' => function (PendingProcess $process) use (&$saved) {
            preg_match('/--from-file=registry\.json=(\S+)/', $process->command, $m);
            $saved = json_decode(file_get_contents($m[1]), true);

            return Process::result();
        },
        '*' => Process::result(),
    ]);

    expect(resolvesToolHostRunner()->host('production'))->toBe('git.example.com')
        ->and($saved)->toHaveCount(1)
        ->and($saved[0]['instance'])->toBe('git-example-com')
        ->and($saved[0]['host'])->toBe('git.example.com');
});
