<?php

use App\Enums\CliTool;
use Illuminate\Support\Facades\Process;

test('cli tools define expected cases and properties', function (): void {
    expect(CliTool::cases())->toHaveCount(8)
        ->and(CliTool::KUBECTL->binary())->toBe('kubectl')
        ->and(CliTool::K9S->binary())->toBe('k9s')
        ->and(CliTool::TOFU->binary())->toBe('tofu')
        ->and(CliTool::HCLOUD->binary())->toBe('hcloud')
        ->and(CliTool::GCLOUD->binary())->toBe('gcloud')
        ->and(CliTool::AWS->binary())->toBe('aws')
        ->and(CliTool::GH->binary())->toBe('gh')
        ->and(CliTool::TEA->binary())->toBe('tea')
        ->and(CliTool::KUBECTL->isDefault())->toBeTrue()
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
    Process::fake([
        'command -v gcloud' => Process::result('', exitCode: 1),
        'command -v brew' => Process::result('', exitCode: 1),
        // A supported interpreter has to be in reach, or the Python precheck
        // stops the install before it is ever attempted.
        'command -v python3.13' => Process::result('/usr/local/bin/python3.13'),
        '*python3.13* -c *' => Process::result('3.13'),
        '*' => Process::result(''),
    ]);

    CliTool::GCLOUD->install();

    Process::assertRan(fn ($process) => str_contains($process->command, '| bash -s -- --disable-prompts')
        && ! str_contains($process->command, '| bash -- '));
});

test('the gcloud installer hands its interpreter over explicitly', function (): void {
    // The bundled install.sh repeats its own python3 discovery otherwise, and
    // on macOS finds the Command Line Tools' 3.9 — the version whose PEP 604
    // type unions make google-cloud-sdk's vendored urllib3 fail to import.
    Process::fake([
        'command -v gcloud' => Process::result('', exitCode: 1),
        'command -v brew' => Process::result('', exitCode: 1),
        'command -v python3.12' => Process::result('/usr/local/bin/python3.12'),
        '*python3.12* -c *' => Process::result('3.12'),
        '*' => Process::result(''),
    ]);

    CliTool::GCLOUD->install();

    Process::assertRan(fn ($process) => str_contains($process->command, 'sdk.cloud.google.com')
        && ($process->environment['CLOUDSDK_PYTHON'] ?? null) === '/usr/local/bin/python3.12');
});

test('an unsupported python stops the gcloud install rather than crashing inside it', function (): void {
    // Every interpreter on PATH reports 3.9, and there is no Homebrew to
    // install a newer one — so refuse, instead of handing the user a urllib3
    // traceback from the middle of Google's installer.
    Process::fake([
        'command -v gcloud' => Process::result('', exitCode: 1),
        'command -v brew' => Process::result('', exitCode: 1),
        'command -v python3' => Process::result('/usr/bin/python3'),
        '*python3* -c *' => Process::result('3.9'),
        '*' => Process::result('', exitCode: 1),
    ]);

    expect(CliTool::GCLOUD->install())->toBeFalse();

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'sdk.cloud.google.com'));
});

test('kubectl is a first-class installable tool', function (): void {
    // It is the one hard dependency of every cluster command, yet it was the
    // only tool the CLI could not install — `which kubectl` plus a URL.
    expect(CliTool::tryFrom('kubectl'))->toBe(CliTool::KUBECTL)
        ->and(CliTool::KUBECTL->isDefault())->toBeTrue()
        ->and(CliTool::KUBECTL->candidatePaths())
        ->toContain('/opt/homebrew/bin/kubectl')
        ->toContain('/usr/local/bin/kubectl')
        ->toContain('/usr/bin/kubectl');
});

test('every case can actually reach an installer', function (): void {
    // install() dispatches through a match arm per case; installK9s() and
    // installTofu() lived only on traits the enum never used, so both arms
    // were a fatal Call to undefined method.
    $missing = [];

    foreach (CliTool::cases() as $tool) {
        $method = 'install'.str_replace(' ', '', ucwords(str_replace('-', ' ', $tool->value)));

        if (! method_exists($tool, $method)) {
            $missing[] = "{$tool->value} => {$method}()";
        }
    }

    expect($missing)->toBeEmpty(implode(', ', $missing));
});
