<?php

use Spatie\TemporaryDirectory\TemporaryDirectory;
use Symfony\Component\Process\Process;

function runDockerCommand(string $docker, array $args, int $timeout = 60): Process
{
    $process = new Process([$docker, ...$args]);
    $process->setTimeout($timeout);
    $process->run();

    return $process;
}

test('real dxflrs/garage:v2.1.0 executes shell-less bootstrap, U4 crash-repair, T1 pre-import, idempotent buckets, and U2 CORS round-trip', function (): void {
    $docker = $this->requireDocker();

    $this->withoutHostBinaryStubs(function () use ($docker): void {
        $tmp = TemporaryDirectory::make()->deleteWhenDestroyed();
        $tomlPath = $tmp->path('garage.toml');
        // V1: NO rpc_secret in garage.toml — injected solely via GARAGE_RPC_SECRET env var.
        file_put_contents($tomlPath, <<<'TOML'
metadata_dir = "/data/meta"
data_dir = "/data/data"
rpc_bind_addr = "[::]:3901"
replication_factor = 1

[s3_api]
s3_region = "us-east-1"
api_bind_addr = "[::]:3900"

[admin]
api_bind_addr = "0.0.0.0:3903"
TOML);

        $cid1 = 'lk-test-garage-1-'.getmypid().'-'.bin2hex(random_bytes(3));
        $cid2 = 'lk-test-garage-2-'.getmypid().'-'.bin2hex(random_bytes(3));
        $rpcSecret1 = bin2hex(random_bytes(32));
        $rpcSecret2 = bin2hex(random_bytes(32));

        try {
            // Confirm /bin/sh is absent in dxflrs/garage:v2.1.0.
            $shCheck = runDockerCommand($docker, ['run', '--rm', '--entrypoint', 'sh', 'dxflrs/garage:v2.1.0', '-c', 'echo nope']);
            expect($shCheck->isSuccessful())->toBeFalse();

            $start1 = runDockerCommand($docker, [
                'run', '-d', '--rm',
                '--name', $cid1,
                '-e', "GARAGE_RPC_SECRET={$rpcSecret1}",
                '-p', '127.0.0.1:0:3900',
                '-v', "{$tomlPath}:/etc/garage.toml:ro",
                'dxflrs/garage:v2.1.0',
                '/garage', 'server',
            ]);
            expect($start1->isSuccessful())->toBeTrue($start1->getErrorOutput());

            // Poll /garage status until healthy node ID appears (real usleep, never Sleep::fake).
            $nodeId = null;
            for ($i = 0; $i < 50; $i++) {
                $status = runDockerCommand($docker, ['exec', $cid1, '/garage', 'status']);
                if ($status->isSuccessful() && preg_match('/^([0-9a-f]{16,})\s/m', $status->getOutput(), $m)) {
                    $nodeId = $m[1];
                    break;
                }
                usleep(200_000);
            }
            expect($nodeId)->not->toBeNull('Garage node did not report healthy status in time.');

            // Layout bootstrap + key create via direct-argv exec.
            expect(runDockerCommand($docker, ['exec', $cid1, '/garage', 'layout', 'assign', $nodeId, '--capacity', '1GB', '--zone', 'commons', '--tag', 'commons'])->isSuccessful())->toBeTrue();
            expect(runDockerCommand($docker, ['exec', $cid1, '/garage', 'layout', 'apply', '--version', '1'])->isSuccessful())->toBeTrue();

            $createAdmin = runDockerCommand($docker, ['exec', $cid1, '/garage', 'key', 'create', 'commons-admin']);
            expect($createAdmin->isSuccessful())->toBeTrue($createAdmin->getErrorOutput());
            preg_match('/Key ID:\s*(\S+)/i', $createAdmin->getOutput(), $accessM);
            preg_match('/Secret key:\s*(\S+)/i', $createAdmin->getOutput(), $secretM);
            $adminKeyId = $accessM[1] ?? '';
            $adminSecret = $secretM[1] ?? '';
            expect($adminKeyId)->not->toBeEmpty()
                ->and($adminSecret)->not->toBeEmpty();

            // U4: Recover existing commons-admin credentials via `--show-secret`.
            $recover = runDockerCommand($docker, ['exec', $cid1, '/garage', 'key', 'info', 'commons-admin', '--show-secret']);
            expect($recover->isSuccessful())->toBeTrue();
            preg_match('/Key ID:\s*(\S+)/i', $recover->getOutput(), $recAccess);
            preg_match('/Secret key:\s*(\S+)/i', $recover->getOutput(), $recSecret);
            expect($recAccess[1] ?? '')->toBe($adminKeyId)
                ->and($recSecret[1] ?? '')->toBe($adminSecret);

            // Create backup-reader key for nightly read-only tests.
            $createReader = runDockerCommand($docker, ['exec', $cid1, '/garage', 'key', 'create', 'backup-reader']);
            expect($createReader->isSuccessful())->toBeTrue();
            preg_match('/Key ID:\s*(\S+)/i', $createReader->getOutput(), $readerAccessM);
            preg_match('/Secret key:\s*(\S+)/i', $createReader->getOutput(), $readerSecretM);
            $readerKeyId = $readerAccessM[1] ?? '';
            $readerSecret = $readerSecretM[1] ?? '';

            // Idempotent bucket create + commons-admin RW + backup-reader R grants.
            $bucket = 'app-alpha';
            expect(runDockerCommand($docker, ['exec', $cid1, '/garage', 'bucket', 'info', $bucket])->isSuccessful())->toBeFalse()
                ->and(runDockerCommand($docker, ['exec', $cid1, '/garage', 'bucket', 'create', $bucket])->isSuccessful())->toBeTrue()
                ->and(runDockerCommand($docker, ['exec', $cid1, '/garage', 'bucket', 'allow', '--read', '--write', $bucket, '--key', 'commons-admin'])->isSuccessful())->toBeTrue()
                ->and(runDockerCommand($docker, ['exec', $cid1, '/garage', 'bucket', 'allow', '--read', $bucket, '--key', 'backup-reader'])->isSuccessful())->toBeTrue();
            // Re-run idempotency check
            expect(runDockerCommand($docker, ['exec', $cid1, '/garage', 'bucket', 'info', $bucket])->isSuccessful())->toBeTrue();
            expect(runDockerCommand($docker, ['exec', $cid1, '/garage', 'bucket', 'allow', '--read', '--write', $bucket, '--key', 'commons-admin'])->isSuccessful())->toBeTrue();

            // U2: Test AWS CLI CORS + backup-reader AccessDenied + temporary --owner revoke in finally.
            $corsFile = $tmp->path('cors.json');
            file_put_contents($corsFile, json_encode([
                'CORSRules' => [[
                    'AllowedHeaders' => ['*'],
                    'AllowedMethods' => ['GET', 'HEAD'],
                    'AllowedOrigins' => ['https://app.example.test'],
                    'MaxAgeSeconds' => 300,
                ]],
            ]));

            // Put one object using commons-admin
            $sampleFile = $tmp->path('hello.txt');
            file_put_contents($sampleFile, 'hello-garage');
            $putObj = runDockerCommand($docker, [
                'run', '--rm', '--network', "container:{$cid1}",
                '-e', "AWS_ACCESS_KEY_ID={$adminKeyId}",
                '-e', "AWS_SECRET_ACCESS_KEY={$adminSecret}",
                '-e', 'AWS_DEFAULT_REGION=us-east-1',
                '-v', "{$sampleFile}:/tmp/hello.txt:ro",
                'amazon/aws-cli:2.27.22',
                's3api', 'put-object', '--endpoint-url', 'http://127.0.0.1:3900',
                '--bucket', $bucket, '--key', 'hello.txt', '--body', '/tmp/hello.txt',
            ]);
            expect($putObj->isSuccessful())->toBeTrue($putObj->getErrorOutput());

            // Grant --owner temporarily, put CORS + get CORS, always revoke --owner in finally.
            expect(runDockerCommand($docker, ['exec', $cid1, '/garage', 'bucket', 'allow', '--owner', $bucket, '--key', 'commons-admin'])->isSuccessful())->toBeTrue();
            try {
                $putCors = runDockerCommand($docker, [
                    'run', '--rm', '--network', "container:{$cid1}",
                    '-e', "AWS_ACCESS_KEY_ID={$adminKeyId}",
                    '-e', "AWS_SECRET_ACCESS_KEY={$adminSecret}",
                    '-e', 'AWS_DEFAULT_REGION=us-east-1',
                    '-v', "{$corsFile}:/tmp/cors.json:ro",
                    'amazon/aws-cli:2.27.22',
                    's3api', 'put-bucket-cors', '--endpoint-url', 'http://127.0.0.1:3900',
                    '--bucket', $bucket, '--cors-configuration', 'file:///tmp/cors.json',
                ]);
                expect($putCors->isSuccessful())->toBeTrue($putCors->getErrorOutput());

                $getCorsAdmin = runDockerCommand($docker, [
                    'run', '--rm', '--network', "container:{$cid1}",
                    '-e', "AWS_ACCESS_KEY_ID={$adminKeyId}",
                    '-e', "AWS_SECRET_ACCESS_KEY={$adminSecret}",
                    '-e', 'AWS_DEFAULT_REGION=us-east-1',
                    'amazon/aws-cli:2.27.22',
                    's3api', 'get-bucket-cors', '--endpoint-url', 'http://127.0.0.1:3900',
                    '--bucket', $bucket,
                ]);
                expect($getCorsAdmin->isSuccessful())->toBeTrue($getCorsAdmin->getErrorOutput())
                    ->and($getCorsAdmin->getOutput())->toContain('https://app.example.test');
            } finally {
                $revoke = runDockerCommand($docker, ['exec', $cid1, '/garage', 'bucket', 'deny', '--owner', $bucket, '--key', 'commons-admin']);
                expect($revoke->isSuccessful())->toBeTrue($revoke->getErrorOutput());
            }

            // Verify commons-admin retains RW only (no O) in `bucket info`.
            $bucketInfo = runDockerCommand($docker, ['exec', $cid1, '/garage', 'bucket', 'info', $bucket]);
            expect($bucketInfo->isSuccessful())->toBeTrue()
                ->and($bucketInfo->getOutput())->toMatch('/^RW\s+\S+\s+commons-admin\b/m');

            // Verify backup-reader can read objects, and gets AccessDenied on get-bucket-cors (cors: "not_captured").
            $listReader = runDockerCommand($docker, [
                'run', '--rm', '--network', "container:{$cid1}",
                '-e', "AWS_ACCESS_KEY_ID={$readerKeyId}",
                '-e', "AWS_SECRET_ACCESS_KEY={$readerSecret}",
                '-e', 'AWS_DEFAULT_REGION=us-east-1',
                'amazon/aws-cli:2.27.22',
                's3api', 'list-objects-v2', '--endpoint-url', 'http://127.0.0.1:3900',
                '--bucket', $bucket,
            ]);
            expect($listReader->isSuccessful())->toBeTrue()
                ->and($listReader->getOutput())->toContain('hello.txt');

            $corsReader = runDockerCommand($docker, [
                'run', '--rm', '--network', "container:{$cid1}",
                '-e', "AWS_ACCESS_KEY_ID={$readerKeyId}",
                '-e', "AWS_SECRET_ACCESS_KEY={$readerSecret}",
                '-e', 'AWS_DEFAULT_REGION=us-east-1',
                'amazon/aws-cli:2.27.22',
                's3api', 'get-bucket-cors', '--endpoint-url', 'http://127.0.0.1:3900',
                '--bucket', $bucket,
            ]);
            expect($corsReader->isSuccessful())->toBeFalse()
                ->and($corsReader->getErrorOutput())->toContain('AccessDenied');

            // T1: Fresh second Garage container pre-imports commons-admin BEFORE key list check.
            $start2 = runDockerCommand($docker, [
                'run', '-d', '--rm',
                '--name', $cid2,
                '-e', "GARAGE_RPC_SECRET={$rpcSecret2}",
                '-v', "{$tomlPath}:/etc/garage.toml:ro",
                'dxflrs/garage:v2.1.0',
                '/garage', 'server',
            ]);
            expect($start2->isSuccessful())->toBeTrue($start2->getErrorOutput());

            $nodeId2 = null;
            for ($i = 0; $i < 50; $i++) {
                $status = runDockerCommand($docker, ['exec', $cid2, '/garage', 'status']);
                if ($status->isSuccessful() && preg_match('/^([0-9a-f]{16,})\s/m', $status->getOutput(), $m)) {
                    $nodeId2 = $m[1];
                    break;
                }
                usleep(200_000);
            }
            expect($nodeId2)->not->toBeNull()
                ->and(runDockerCommand($docker, ['exec', $cid2, '/garage', 'layout', 'assign', $nodeId2, '--capacity', '1GB', '--zone', 'commons', '--tag', 'commons'])->isSuccessful())->toBeTrue()
                ->and(runDockerCommand($docker, ['exec', $cid2, '/garage', 'layout', 'apply', '--version', '1'])->isSuccessful())->toBeTrue();

            $import = runDockerCommand($docker, ['exec', $cid2, '/garage', 'key', 'import', '--yes', $adminKeyId, $adminSecret, '-n', 'commons-admin']);
            expect($import->isSuccessful())->toBeTrue($import->getErrorOutput());

            $keyList2 = runDockerCommand($docker, ['exec', $cid2, '/garage', 'key', 'list']);
            expect($keyList2->isSuccessful())->toBeTrue()
                ->and(substr_count($keyList2->getOutput(), 'commons-admin'))->toBe(1);

            $info2 = runDockerCommand($docker, ['exec', $cid2, '/garage', 'key', 'info', 'commons-admin', '--show-secret']);
            expect($info2->isSuccessful())->toBeTrue()
                ->and($info2->getOutput())->toContain($adminKeyId)
                ->and($info2->getOutput())->toContain($adminSecret);
        } finally {
            runDockerCommand($docker, ['rm', '-f', $cid1]);
            runDockerCommand($docker, ['rm', '-f', $cid2]);
        }
    });
});
