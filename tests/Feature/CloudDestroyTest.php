<?php

use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

test('cloud:destroy warns when no stacks or unfinished setups exist', function (): void {
    $tempDir = TemporaryDirectory::make()->deleteWhenDestroyed();
    $oldHome = $_SERVER['HOME'] ?? null;
    $_SERVER['HOME'] = $tempDir->path();

    try {
        $this->artisan('cloud:destroy', ['--force' => true])
            ->expectsOutputToContain('No stacks or unfinished setups found')
            ->assertExitCode(0);
    } finally {
        if ($oldHome !== null) {
            $_SERVER['HOME'] = $oldHome;
        }
    }
});

test('cloud:destroy detects and destroys an unfinished stack setup', function (): void {
    $tempDir = TemporaryDirectory::make()->deleteWhenDestroyed();
    $oldHome = $_SERVER['HOME'] ?? null;
    $_SERVER['HOME'] = $tempDir->path();

    $tofuDir = $tempDir->path().'/.larakube/tofu/unfinished-gcp-vps';
    @mkdir($tofuDir, 0700, true);

    file_put_contents($tofuDir.'/main.tf', <<<'HCL'
provider "google" {
  project = "my-test-proj"
  region  = "us-central1"
}
resource "google_compute_instance" "larakube" {
  name = "unfinished-gcp-vps"
}
HCL
    );

    file_put_contents($tofuDir.'/terraform.tfstate', json_encode([
        'version' => 4,
        'terraform_version' => '1.6.2',
        'outputs' => [
            'ip' => ['value' => '34.100.200.50'],
        ],
        'resources' => [
            [
                'mode' => 'managed',
                'type' => 'google_compute_instance',
                'name' => 'larakube',
                'instances' => [
                    [
                        'attributes' => [
                            'project' => 'my-test-proj',
                        ],
                    ],
                ],
            ],
        ],
    ]));

    // Put a dummy binary so resolveTofuBinary works
    $fakeTofu = $tempDir->path().'/tofu';
    file_put_contents($fakeTofu, "#!/bin/sh\nexit 0\n");
    chmod($fakeTofu, 0755);

    Process::fake([
        'command -v tofu' => Process::result($fakeTofu),
        '*destroy*' => Process::result(output: 'Destroy complete!'),
    ]);

    try {
        expect(file_exists($tofuDir))->toBeTrue();

        $this->artisan('cloud:destroy', [
            'stack' => 'unfinished-gcp-vps',
            '--force' => true,
        ])
            ->expectsOutputToContain("Cloud Destroy: 'unfinished-gcp-vps'")
            ->expectsOutputToContain("✅ Destroyed and unregistered 'unfinished-gcp-vps'")
            ->assertExitCode(0);

        // The unfinished tofu workdir should be cleaned up
        expect(file_exists($tofuDir))->toBeFalse();
    } finally {
        if ($oldHome !== null) {
            $_SERVER['HOME'] = $oldHome;
        }
    }
});
