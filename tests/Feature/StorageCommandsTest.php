<?php

use Illuminate\Support\Facades\Process;

function storageFakes(array $overrides = []): array
{
    return array_merge([
        '*get configmap plex-commons*' => Process::result(
            output: (string) json_encode(['version' => 1, 'services' => ['seaweedfs' => ['enabled' => true]]]),
        ),
        '*get secret plex-admin*' => Process::result(
            output: '{"data":{"S3_ACCESS_KEY":"bGFyYWt1YmU=","S3_SECRET_KEY":"MmM5Mzc2MGRkNWQyM2JjZTczMmZmZTM3ZGViYTdiNDI="}}',
        ),
        '*exec*s3.bucket.list*' => Process::result(output: "stalwart\nuploads\n"),
        '*exec*s3.bucket.create*' => Process::result(output: 'bucket created'),
        '*exec*fs.ls*' => Process::result(output: "file1.txt\nfile2.png\n"),
        '*' => Process::result(output: ''),
    ], $overrides);
}

test('storage:show refuses when there is no Plex Commons', function () {
    Process::fake([
        '*get configmap plex-commons*' => Process::result(output: '', exitCode: 1),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('storage:show local')
        ->assertExitCode(1)
        ->expectsOutputToContain('No Plex Commons found');
});

test('storage:show rejects an unknown driver', function () {
    Process::fake(storageFakes());

    $this->artisan('storage:show local --driver=invalid')
        ->assertExitCode(1)
        ->expectsOutputToContain("Unknown storage driver 'invalid'");
});

test('storage:show displays storage status and bucket list', function () {
    Process::fake(storageFakes());

    $this->artisan('storage:show local')
        ->assertExitCode(0)
        ->expectsOutputToContain('Commons S3 Storage')
        ->expectsOutputToContain('SeaweedFS')
        ->expectsOutputToContain('stalwart');
});

test('storage:buckets renders a table of buckets', function () {
    Process::fake(storageFakes());

    $this->artisan('storage:buckets local')
        ->assertExitCode(0)
        ->expectsOutputToContain('Buckets on SeaweedFS (High Performance)')
        ->expectsOutputToContain('stalwart');
});

test('storage:make-bucket creates a bucket via flag', function () {
    Process::fake(storageFakes());

    $this->artisan('storage:make-bucket local --bucket=my-app-uploads')
        ->assertExitCode(0)
        ->expectsOutputToContain("Created bucket 'my-app-uploads'");
});

test('storage:list lists files inside a bucket', function () {
    Process::fake(storageFakes());

    $this->artisan('storage:list local --bucket=stalwart')
        ->assertExitCode(0)
        ->expectsOutputToContain("Files in 'stalwart'")
        ->expectsOutputToContain('file1.txt');
});
