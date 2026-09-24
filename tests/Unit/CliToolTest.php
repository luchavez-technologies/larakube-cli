<?php

use App\Enums\CliTool;
use Illuminate\Support\Facades\Process;

test('cli tools define expected cases and properties', function (): void {
    expect(CliTool::cases())->toHaveCount(7)
        ->and(CliTool::K9S->binary())->toBe('k9s')
        ->and(CliTool::TOFU->binary())->toBe('tofu')
        ->and(CliTool::HCLOUD->binary())->toBe('hcloud')
        ->and(CliTool::GCLOUD->binary())->toBe('gcloud')
        ->and(CliTool::AWS->binary())->toBe('aws')
        ->and(CliTool::GH->binary())->toBe('gh')
        ->and(CliTool::TEA->binary())->toBe('tea')
        ->and(CliTool::K9S->isDefault())->toBeTrue()
        ->and(CliTool::TOFU->isDefault())->toBeTrue()
        ->and(CliTool::HCLOUD->isDefault())->toBeFalse()
        ->and(CliTool::GCLOUD->isDefault())->toBeFalse()
        ->and(CliTool::AWS->isDefault())->toBeFalse()
        ->and(CliTool::GH->isDefault())->toBeFalse()
        ->and(CliTool::TEA->isDefault())->toBeFalse();

    foreach (CliTool::cases() as $tool) {
        expect($tool->label())->not->toBeEmpty()
            ->and($tool->description())->not->toBeEmpty()
            ->and($tool->candidatePaths())->not->toBeEmpty();
    }
});

test('cli tool candidate paths include platform locations', function (): void {
    $tofuPaths = CliTool::TOFU->candidatePaths();
    expect($tofuPaths)->toContain('/opt/homebrew/bin/tofu')
        ->toContain('/opt/homebrew/bin/terraform');

    $gcloudPaths = CliTool::GCLOUD->candidatePaths();
    expect($gcloudPaths)->toContain('/opt/homebrew/bin/gcloud')
        ->toContain('/usr/bin/gcloud');

    $awsPaths = CliTool::AWS->candidatePaths();
    expect($awsPaths)->toContain('/snap/bin/aws')
        ->toContain('/usr/bin/aws');
});

test('resolveBinary finds binary if available', function (): void {
    Process::fake([
        'command -v k9s' => Process::result('/usr/local/bin/k9s'),
        'command -v missing-tool' => Process::result('', exitCode: 1),
    ]);

    expect(CliTool::K9S->resolveBinary())->toBeString();
});

test('ensureAuth returns true immediately for non-interactive tools', function (): void {
    expect(CliTool::K9S->ensureAuth())->toBeTrue()
        ->and(CliTool::TOFU->ensureAuth())->toBeTrue()
        ->and(CliTool::HCLOUD->ensureAuth())->toBeTrue()
        ->and(CliTool::GH->ensureAuth())->toBeTrue()
        ->and(CliTool::TEA->ensureAuth())->toBeTrue();
});

test('ensureAuth handles aws authentication detection', function (): void {
    Process::fake([
        'command -v aws' => Process::result('/usr/local/bin/aws'),
        '/usr/local/bin/aws sts get-caller-identity 2>/dev/null' => Process::result('{"Account": "123456789012"}'),
    ]);

    expect(CliTool::AWS->ensureAuth(prompt: false))->toBeTrue();
});

test('the gcloud installer passes its flags to the piped script, not to bash', function (): void {
    // `bash -- --disable-prompts` makes bash read a FILE named
    // --disable-prompts instead of the piped script, so the install died with
    // "bash: --disable-prompts: No such file or directory" and gcloud was
    // never installed. -s is what says "the script is on stdin".
    $command = CliTool::gcloudInstallCommand('/home/dev');

    expect($command)->toContain('| bash -s -- --disable-prompts')
        ->and($command)->not->toContain('| bash -- ')
        ->and($command)->toContain("--install-dir='/home/dev'");
});
