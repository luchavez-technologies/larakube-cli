<?php

use App\Traits\InteractsWithKustomize;

function kustomizeHarness(?string $bin): object
{
    return new class($bin)
    {
        use InteractsWithKustomize;

        public function __construct(private ?string $bin) {}

        public function build(string $path): string
        {
            return $this->kustomizeBuildCommand($path);
        }

        public function apply(string $path): string
        {
            return $this->kustomizeApplyCommand($path);
        }

        // Stand in for the resolved binary so we can test command shaping without a
        // real install on disk.
        protected function kustomizeBin(): ?string
        {
            return $this->bin;
        }
    };
}

test('build/apply fall back to kubectl when no standalone kustomize is installed', function (): void {
    $h = kustomizeHarness(null);

    expect($h->build('overlays/local'))->toBe('kubectl kustomize '.escapeshellarg('overlays/local'))
        ->and($h->apply('overlays/local'))->toBe('kubectl apply -k '.escapeshellarg('overlays/local'));
});

test('build/apply route through the standalone kustomize when present', function (): void {
    $bin = '/home/u/.larakube/bin/kustomize';
    $h = kustomizeHarness($bin);

    expect($h->build('overlays/local'))->toBe(escapeshellarg($bin).' build '.escapeshellarg('overlays/local'))
        ->and($h->apply('overlays/local'))->toBe(escapeshellarg($bin).' build '.escapeshellarg('overlays/local').' | kubectl apply -f -');
});
