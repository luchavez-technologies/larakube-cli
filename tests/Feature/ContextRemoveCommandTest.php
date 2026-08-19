<?php

/**
 * context:remove used to run bare `kubectl config delete-*` calls, which
 * follow the shell's own $KUBECONFIG when one is set (e.g. k3s's own docs
 * suggest exporting /etc/rancher/k3s/k3s.yaml) — a real user hit this via
 * cloud:destroy's "also remove the local kube-context?" offer: the delete
 * failed because kubectl looked in the k3s file, not ~/.kube/config, which
 * is where LaraKube always merges cloud contexts. These tests fake a real
 * HOME (same pattern as PrunesKubeContextTest) and assert every kubectl
 * call is pinned to that file explicitly.
 */

use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

function withFakeKubeHomeForRemove(callable $callback): void
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

test('context:remove targets ~/.kube/config explicitly, not the shell\'s $KUBECONFIG', function (): void {
    withFakeKubeHomeForRemove(function (string $home): void {
        $kubeConfig = $home.'/.kube/config';
        $kc = 'KUBECONFIG='.escapeshellarg($kubeConfig).' kubectl config';

        Process::fake([
            "{$kc} delete-context 'larakube-1.2.3.4'" => Process::result(exitCode: 0),
            '*' => '',
        ]);

        $this->artisan('context:remove larakube-1.2.3.4 --force')
            ->assertExitCode(0)
            ->expectsOutputToContain("✅ Removed context 'larakube-1.2.3.4'");

        Process::assertRan("{$kc} delete-context 'larakube-1.2.3.4'");
        Process::assertRan("{$kc} delete-cluster 'larakube-1.2.3.4'");
        Process::assertRan("{$kc} delete-user 'larakube-1.2.3.4'");
    });
});

test('context:remove reports failure instead of a stale unrelated kubeconfig error', function (): void {
    withFakeKubeHomeForRemove(function (string $home): void {
        $kubeConfig = $home.'/.kube/config';
        $kc = 'KUBECONFIG='.escapeshellarg($kubeConfig).' kubectl config';

        Process::fake([
            "{$kc} delete-context 'larakube-1.2.3.4'" => Process::result(
                output: '',
                errorOutput: "error: cannot delete context larakube-1.2.3.4, not in {$kubeConfig}",
                exitCode: 1,
            ),
            '*' => '',
        ]);

        $this->artisan('context:remove larakube-1.2.3.4 --force')
            ->assertExitCode(1)
            ->expectsOutputToContain("Failed to remove context 'larakube-1.2.3.4'");
    });
});
