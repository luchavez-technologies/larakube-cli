<?php

use App\Commands\Resume\ResumeInitCommand;
use Illuminate\Support\Facades\Process;

function resumeCommonsSpec(?string $s3Host): array
{
    $seaweedfs = ['enabled' => true, 'port' => 8333];
    if ($s3Host !== null) {
        $seaweedfs['host'] = $s3Host;
    }

    return [
        'services' => [
            'postgres' => ['enabled' => true],
            'seaweedfs' => $seaweedfs,
        ],
    ];
}

function fakeResumeInitProcess(?string $s3Host, ?string &$appliedManifest, int $applyExitCode = 0): void
{
    $spec = resumeCommonsSpec($s3Host);

    Process::fake(function ($process) use ($spec, &$appliedManifest, $applyExitCode) {
        $cmd = $process->command;

        if (str_contains($cmd, 'apply -f')) {
            preg_match('/apply -f (\'[^\']*\'|"[^"]*"|\S+)/', $cmd, $m);
            $path = trim($m[1] ?? '', '\'"');
            if ($path !== '' && file_exists($path) && str_contains($path, 'larakube-resume-reactive')) {
                $appliedManifest = file_get_contents($path);
            }

            return Process::result(output: 'applied', exitCode: $applyExitCode);
        }

        return match (true) {
            str_contains($cmd, 'get configmap plex-commons') => Process::result(output: json_encode($spec)),
            str_contains($cmd, 'get configmap plex-registry') => Process::result(output: '', exitCode: 1),
            str_contains($cmd, 'S3_ACCESS_KEY') => Process::result(output: base64_encode('larakube')),
            str_contains($cmd, 'S3_SECRET_KEY') => Process::result(output: base64_encode('s3-secret')),
            str_contains($cmd, 'rollout status') => Process::result(output: 'deployment "resume-reactive" successfully rolled out'),
            default => Process::result(output: ''),
        };
    });
}

test('resume:init deploys Reactive Resume and applies manifests successfully', function () {
    $appliedManifest = null;
    fakeResumeInitProcess('files.example.com', $appliedManifest);

    $this->artisan(ResumeInitCommand::class, [
        'environment' => 'local',
        '--no-interaction' => true,
    ])->assertExitCode(0);

    expect($appliedManifest)->not->toBeNull()
        ->and($appliedManifest)->toContain('https://files.example.com')
        ->and($appliedManifest)->toContain('amruthpillai/reactive-resume');
});

test('resume:init returns a failing exit code when kubectl apply fails', function () {
    $appliedManifest = null;
    fakeResumeInitProcess('files.example.com', $appliedManifest, applyExitCode: 1);

    $this->artisan(ResumeInitCommand::class, [
        'environment' => 'local',
        '--no-interaction' => true,
    ])
        ->assertExitCode(1)
        ->doesntExpectOutputToContain('Reactive Resume is live');
});
