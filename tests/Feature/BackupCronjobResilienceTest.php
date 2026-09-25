<?php

use Symfony\Component\Yaml\Yaml;

/**
 * Two volume targets, one of which will be unreachable at run time — the shape
 * a rename leaves behind, since the CronJob freezes these names at
 * `backup:schedule` time.
 */
function backupCronjobResilienceManifest(): string
{
    return view('k8s.backup.cronjob', [
        'schedule' => '17 3 * * *',
        'timezone' => 'UTC',
        'volumes' => [
            ['name' => 'seaweedfs', 'namespace' => 'larakube-plex', 'deployment' => 'seaweedfs', 'container' => 'seaweedfs', 'paths' => ['/data']],
            ['name' => 'forgejo', 'namespace' => 'larakube-shared', 'deployment' => 'forgejo', 'container' => 'forgejo', 'paths' => ['/data']],
        ],
        'dbDriver' => 'postgres',
        'dbService' => 'postgres',
        'dbListCommand' => 'psql -l',
        'dbDumpTemplate' => 'pg_dump __DB__',
    ])->render();
}

/** @return array<string, string> container/initContainer name => its script */
function backupCronjobScripts(): array
{
    // The manifest ships the ServiceAccount and RBAC alongside the CronJob.
    $cronjob = null;

    foreach (preg_split('/^---$/m', backupCronjobResilienceManifest()) as $document) {
        $parsed = Yaml::parse($document);

        if (($parsed['kind'] ?? null) === 'CronJob') {
            $cronjob = $parsed;
        }
    }

    expect($cronjob)->not->toBeNull('no CronJob document in the rendered manifest');

    $spec = $cronjob['spec']['jobTemplate']['spec']['template']['spec'];
    $scripts = [];

    foreach (array_merge($spec['initContainers'] ?? [], $spec['containers'] ?? []) as $container) {
        $scripts[$container['name']] = end($container['command']);
    }

    return $scripts;
}

test('one unreachable volume does not abort the whole nightly run', function (): void {
    $dump = backupCronjobScripts()['dump'];

    // Each target is guarded on its own. Before this, `set -euo pipefail` plus a
    // bare `exit 1` meant the first missing Deployment ended the run with the
    // databases already dumped and nothing uploaded.
    expect($dump)
        ->toContain('echo "forgejo" >> MISSING')
        ->toContain('echo "seaweedfs" >> MISSING')
        ->and(substr_count($dump, 'ARCHIVED=no'))->toBe(4);

    // A target that failed must not leave a stub behind for the encrypt stage
    // to pick up and the upload stage to record as a real archive.
    expect($dump)->toContain('rm -f "vol-forgejo.tar.gz"');
});

test('an incomplete backup is uploaded, recorded, and then fails the job', function (): void {
    $upload = backupCronjobScripts()['upload'];

    $manifestAt = strpos($upload, 's3 cp manifest.json');
    $failAt = strpos($upload, 'exit 1');

    expect($manifestAt)->not->toBeFalse()
        ->and($failAt)->not->toBeFalse()
        // Order is the whole point: fail AFTER the upload. A partial archive in
        // the bucket beats none, but the Job still has to go red.
        ->and($failAt)->toBeGreaterThan($manifestAt)
        ->and($upload)->toContain('"missing":[%s]')
        ->toContain('INCOMPLETE — not in this backup:');
});

test('every generated script is valid shell', function (): void {
    // A Blade template that emits broken bash fails at 3am inside a container,
    // where the only evidence left is a Failed Job with garbage-collected pods.
    foreach (backupCronjobScripts() as $name => $script) {
        $file = tempnam(sys_get_temp_dir(), 'lk-backup-');
        file_put_contents($file, $script);

        exec('bash -n '.escapeshellarg($file).' 2>&1', $output, $exit);
        @unlink($file);

        expect($exit)->toBe(0, "{$name} is not valid shell:\n".implode("\n", $output));
    }
});
