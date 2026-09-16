<?php

use App\Enums\SharedClusterService;
use App\Traits\InteractsWithTraefik;
use App\Traits\LaraKubeOutput;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * `up` re-applies every installed install-gated shared service with nothing but
 * its host. A template that also carried Secrets or Deployments would be applied
 * with empty credentials over a real install, so every probe-gated service must
 * reconcile an Ingress and nothing else.
 */
function reconcileSafetyKinds(string $yaml): array
{
    return collect(preg_split('/^---$/m', $yaml))
        ->map(fn (string $doc) => trim($doc))
        ->filter()
        ->map(fn (string $doc) => Yaml::parse($doc)['kind'] ?? null)
        ->values()
        ->all();
}

function reconcileSafetyRunner(): object
{
    return new class
    {
        use InteractsWithTraefik, LaraKubeOutput;

        public function apply(SharedClusterService $service, string $host): void
        {
            $this->applySharedService($service, $host);
        }
    };
}

test('every probe-gated shared service reconciles an Ingress and nothing else', function (): void {
    Process::fake();

    foreach (SharedClusterService::cases() as $service) {
        if ($service->presenceProbe() === null) {
            continue;
        }

        $yaml = view($service->template(), [
            'host' => $service->hostFor('example.test'),
            'isLocal' => true,
            ...$service->templatePayload(),
        ])->render();

        expect(reconcileSafetyKinds($yaml))->toBe(['Ingress'], "{$service->value} reconciles more than an Ingress");
    }
});

test('Forgejo is detected by its tool label, not the instance-suffixed Deployment name', function (): void {
    expect(SharedClusterService::FORGEJO->presenceProbe())->toBe('deployment -l larakube-tool=git -n larakube-shared');
});

test('up reconciles an installed Forgejo by applying only its instance Ingress', function (): void {
    $applied = [];

    Process::fake([
        '*get deployment -l larakube-tool=git*' => Process::result(output: 'git-forgejo-git-example-test   1/1   1   1   5d'),
        '*create namespace*' => Process::result(output: 'namespace/larakube-shared configured'),
        'kubectl apply -f *' => function (PendingProcess $process) use (&$applied) {
            $applied[] = file_get_contents(substr($process->command, strlen('kubectl apply -f ')));

            return Process::result(output: 'applied');
        },
        '*' => Process::result(),
    ]);

    reconcileSafetyRunner()->apply(SharedClusterService::FORGEJO, 'git.example.test');

    expect($applied)->toHaveCount(1);

    $ingress = Yaml::parse(trim($applied[0]));

    expect(reconcileSafetyKinds($applied[0]))->toBe(['Ingress'])
        ->and($ingress['metadata']['name'])->toBe('git-forgejo-git-example-test')
        ->and($ingress['spec']['rules'][0]['http']['paths'][0]['backend']['service']['name'])->toBe('git-forgejo-http-git-example-test');
});

test('up skips Forgejo entirely when no labelled Deployment exists', function (): void {
    Process::fake([
        '*get deployment -l larakube-tool=git*' => Process::result(output: ''),
        '*' => Process::result(),
    ]);

    reconcileSafetyRunner()->apply(SharedClusterService::FORGEJO, 'git.example.test');

    Process::assertNotRan(fn (PendingProcess $process) => str_contains($process->command, 'apply -f'));
});
