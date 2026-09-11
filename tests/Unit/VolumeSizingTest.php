<?php

use App\Traits\InteractsWithVolumeSizing;
use Illuminate\Support\Facades\Process;

function volumeSizingSubject(): object
{
    return new class
    {
        use InteractsWithVolumeSizing;

        public function bytes(string $q): int
        {
            return $this->quantityToBytes($q);
        }

        public function resolver(string $ns): Closure
        {
            return $this->volumeSizeResolver('kubectl', $ns);
        }

        public function sizes(string $ns): array
        {
            return $this->liveVolumeSizes('kubectl', $ns);
        }
    };
}

test('quantity parsing covers the suffixes Kubernetes accepts on storage', function (): void {
    $s = volumeSizingSubject();

    expect($s->bytes('1Gi'))->toBe(1024 ** 3)
        ->and($s->bytes('2Gi'))->toBeGreaterThan($s->bytes('1Gi'))
        ->and($s->bytes('1024Mi'))->toBe($s->bytes('1Gi'))
        ->and($s->bytes('128Mi'))->toBe(128 * 1024 ** 2)
        ->and($s->bytes('1G'))->toBe(1000 ** 3)
        ->and($s->bytes('1Ti'))->toBe(1024 ** 4);
});

test('an unparseable quantity is zero so it always loses a comparison', function (): void {
    // Never the other way round: a garbage value that parsed HIGH would win
    // max() and silently shrink nothing / grow everything.
    $s = volumeSizingSubject();

    expect($s->bytes('banana'))->toBe(0)
        ->and($s->bytes(''))->toBe(0)
        ->and($s->bytes('10 Gi extra'))->toBe(0);
});

test('a claim that does not exist yet gets the template default', function (): void {
    Process::fake(['*' => Process::result(output: '')]);

    $size = volumeSizingSubject()->resolver('larakube-shared');

    expect($size('chat-synapse-data', '5Gi'))->toBe('5Gi');
});

test('a claim already larger than the default keeps its live size', function (): void {
    // This is the whole point: re-applying the template literal after a
    // storage:resize would be rejected by the API server, because a PVC can
    // only ever grow. See ADR 0023.
    Process::fake([
        '*get pvc -n *' => Process::result(output: "chat-synapse-data=20Gi\n"),
        '*' => Process::result(output: ''),
    ]);

    $size = volumeSizingSubject()->resolver('larakube-shared');

    expect($size('chat-synapse-data', '5Gi'))->toBe('20Gi');
});

test('a raised template default still wins over a smaller live claim', function (): void {
    // The other direction has to work too, or bumping a shipped default in a
    // release would silently never take effect on existing installs.
    Process::fake([
        '*get pvc -n *' => Process::result(output: "chat-synapse-data=5Gi\n"),
        '*' => Process::result(output: ''),
    ]);

    $size = volumeSizingSubject()->resolver('larakube-shared');

    expect($size('chat-synapse-data', '10Gi'))->toBe('10Gi');
});

test('live sizes read the request, not the reported capacity', function (): void {
    // On a hostPath provisioner status.capacity is just the request echoed
    // back, and mid-expansion the two disagree. The request is what the next
    // apply is compared against.
    Process::fake([
        '*get pvc -n *' => Process::result(output: "a=1Gi\nb=2Gi\n"),
        '*' => Process::result(output: ''),
    ]);

    expect(volumeSizingSubject()->sizes('larakube-shared'))->toBe(['a' => '1Gi', 'b' => '2Gi']);

    Process::assertRan(fn ($process) => str_contains($process->command, 'spec.resources.requests.storage')
        && ! str_contains($process->command, 'status.capacity'));
});
