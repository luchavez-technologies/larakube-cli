<?php

/**
 * pruneKubeContext() shells out to `kubectl config` against ~/.kube/config
 * explicitly (via a KUBECONFIG= prefix), so these tests fake a real HOME
 * directory with a kubeconfig file present, then assert on which commands
 * ran via Process::fake()'s spy.
 */

use App\Traits\PrunesKubeContext;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

function pruneKubeContextHelper(): object
{
    return new class
    {
        use PrunesKubeContext;

        public function prune(array $contexts): void
        {
            $this->pruneKubeContext($contexts);
        }
    };
}

function withFakeKubeHome(callable $callback): void
{
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $dir = $temporaryDirectory->path();
    mkdir($dir.'/.kube', 0755, true);
    file_put_contents($dir.'/.kube/config', "apiVersion: v1\nkind: Config\n");

    $original = $_SERVER['HOME'] ?? null;
    $_SERVER['HOME'] = $dir;

    try {
        $callback($dir);
    } finally {
        if ($original === null) {
            unset($_SERVER['HOME']);
        } else {
            $_SERVER['HOME'] = $original;
        }
        $temporaryDirectory->delete();
    }
}

test('pruneKubeContext deletes the context, cluster, and user entries', function (): void {
    withFakeKubeHome(function (string $home): void {
        $kubeConfig = $home.'/.kube/config';
        $kc = 'KUBECONFIG='.escapeshellarg($kubeConfig).' kubectl config';

        Process::fake([
            "{$kc} current-context" => "other-context\n",
            '*' => '',
        ]);

        pruneKubeContextHelper()->prune(['k3s-larakube']);

        Process::assertRan("{$kc} delete-context 'k3s-larakube'");
        Process::assertRan("{$kc} delete-cluster 'k3s-larakube'");
        Process::assertRan("{$kc} delete-user 'k3s-larakube'");
    });
});

test('pruneKubeContext unsets current-context only when it points at a pruned context', function (): void {
    withFakeKubeHome(function (string $home): void {
        $kubeConfig = $home.'/.kube/config';
        $kc = 'KUBECONFIG='.escapeshellarg($kubeConfig).' kubectl config';

        Process::fake([
            "{$kc} current-context" => "k3s-larakube\n",
            '*' => '',
        ]);

        pruneKubeContextHelper()->prune(['k3s-larakube']);

        Process::assertRan("{$kc} unset current-context");
    });
});

test('pruneKubeContext leaves current-context alone when it points elsewhere', function (): void {
    withFakeKubeHome(function (string $home): void {
        $kubeConfig = $home.'/.kube/config';
        $kc = 'KUBECONFIG='.escapeshellarg($kubeConfig).' kubectl config';

        Process::fake([
            "{$kc} current-context" => "some-other-context\n",
            '*' => '',
        ]);

        pruneKubeContextHelper()->prune(['k3s-larakube']);

        Process::assertNotRan("{$kc} unset current-context");
    });
});

test('pruneKubeContext is a no-op when there is no kubeconfig file', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $dir = $temporaryDirectory->path();
    $original = $_SERVER['HOME'] ?? null;
    $_SERVER['HOME'] = $dir;

    Process::fake();

    try {
        pruneKubeContextHelper()->prune(['k3s-larakube']);
        Process::assertNothingRan();
    } finally {
        if ($original === null) {
            unset($_SERVER['HOME']);
        } else {
            $_SERVER['HOME'] = $original;
        }
        $temporaryDirectory->delete();
    }
});
