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

/**
 * Runs a generated install command for real against a `curl` stand-in, so the version lookup and the download
 * address are what is tested, with no network. The stand-in answers GitHub's redirect and Gitea's API, serves a
 * gh tarball, and writes the address it was asked for into any file it is told to save.
 */
function installWithFakeCurl(string $command, array $answers): array
{
    static $keep = [];
    $keep[] = $temporary = Spatie\TemporaryDirectory\TemporaryDirectory::make()->deleteWhenDestroyed();
    $dir = $temporary->path();
    mkdir($dir.'/bin', 0755, true);
    mkdir($dir.'/out', 0755, true);
    mkdir($dir.'/fixture/gh_9.9.9_linux_amd64/bin', 0755, true);
    file_put_contents($dir.'/fixture/gh_9.9.9_linux_amd64/bin/gh', "#!/bin/sh\necho gh 9.9.9\n");
    chmod($dir.'/fixture/gh_9.9.9_linux_amd64/bin/gh', 0755);

    file_put_contents($dir.'/bin/curl', <<<'SH'
#!/bin/sh
case "$*" in
  *url_effective*) printf '%s' "$FAKE_REDIRECT" ;;
  *api/v1/repos/gitea/tea/releases/latest*) printf '%s' "$FAKE_TEA_API" ;;
  *releases/download/v9.9.9/gh_9.9.9_linux_amd64.tar.gz*) tar -cz -C "$FAKE_FIXTURE" gh_9.9.9_linux_amd64 ;;
  *) out=''; prev=''; for arg in "$@"; do [ "$prev" = "-o" ] && out="$arg"; prev="$arg"; done
     [ -n "$out" ] && printf '%s' "$*" > "$out" ;;
esac
SH);
    chmod($dir.'/bin/curl', 0755);

    $env = 'PATH='.escapeshellarg($dir.'/bin:'.getenv('PATH')).' FAKE_FIXTURE='.escapeshellarg($dir.'/fixture')
        .' FAKE_REDIRECT='.escapeshellarg($answers['redirect'] ?? '').' FAKE_TEA_API='.escapeshellarg($answers['tea'] ?? '');

    exec($env.' sh -c '.escapeshellarg(str_replace('{out}', $dir.'/out', $command)).' 2>&1', $output, $code);

    $result = ['code' => $code, 'output' => implode("\n", $output), 'dir' => $dir.'/out'];

    return $result;
}

test('the GitHub CLI is installed at whatever version GitHub says is current', function (): void {
    $result = installWithFakeCurl(CliTool::ghInstallCommand('amd64', '{out}'), ['redirect' => 'https://github.com/cli/cli/releases/tag/v9.9.9']);

    expect($result['code'])->toBe(0)
        ->and(trim((string) shell_exec(escapeshellarg($result['dir'].'/gh'))))->toBe('gh 9.9.9');
});

test('the GitHub CLI install stops, saying why, when the current release cannot be found', function (): void {
    // GitHub did not redirect to a tag: the answer is the address that was asked.
    $result = installWithFakeCurl(CliTool::ghInstallCommand('amd64', '{out}'), ['redirect' => 'https://github.com/cli/cli/releases/latest']);

    expect($result['code'])->not->toBe(0)
        ->and($result['output'])->toContain('Could not find the latest GitHub CLI release')
        ->and(file_exists($result['dir'].'/gh'))->toBeFalse();
});

test('tea is downloaded at the version Gitea reports as the latest', function (): void {
    $result = installWithFakeCurl(CliTool::teaInstallCommand('arm64', '{out}/tea'), ['tea' => '{"id":1,"tag_name":"v7.7.7","name":"v7.7.7"}']);

    expect($result['code'])->toBe(0)
        ->and((string) file_get_contents($result['dir'].'/tea'))->toContain('https://dl.gitea.com/tea/7.7.7/tea-7.7.7-linux-arm64');
});

test('tea falls back to a known version only when Gitea cannot be asked', function (): void {
    $result = installWithFakeCurl(CliTool::teaInstallCommand('amd64', '{out}/tea'), ['tea' => '']);

    expect($result['code'])->toBe(0)
        ->and((string) file_get_contents($result['dir'].'/tea'))->toContain('https://dl.gitea.com/tea/0.16.0/tea-0.16.0-linux-amd64');
});

test('neither install carries a version of its own, apart from tea\'s fallback', function (): void {
    $source = (string) file_get_contents(base_path('app/Enums/CliTool.php'));

    expect($source)->not->toContain("'2.67.0'")
        ->and(substr_count($source, "'0.16.0'"))->toBe(1);
});

test('binDir defaults to larakube bin and respects LARAKUBE_BIN_DIR', function (): void {
    putenv('LARAKUBE_BIN_DIR');
    expect(CliTool::binDir())->toBe(home_path('.larakube/bin'));

    putenv('LARAKUBE_BIN_DIR=/custom/bin/path');
    expect(CliTool::binDir())->toBe('/custom/bin/path');

    putenv('LARAKUBE_BIN_DIR=/custom/bin/path/');
    expect(CliTool::binDir())->toBe('/custom/bin/path');

    putenv('LARAKUBE_BIN_DIR');
});

test('kubectl install command downloads stable release to binDir without sudo', function (): void {
    $cmd = CliTool::kubectlInstallCommand('darwin', 'arm64', '/tmp/test-bin');

    expect($cmd)->toContain('dl.k8s.io/release/stable.txt')
        ->toContain('darwin/arm64/kubectl')
        ->toContain("install -m 0755 \"\$T/kubectl\" '/tmp/test-bin/kubectl'")
        ->not->toContain('sudo');
});

test('k9s install command downloads latest archive for darwin and linux', function (): void {
    $darwinCmd = CliTool::k9sInstallCommand('darwin', 'arm64', '/tmp/test-bin');
    expect($darwinCmd)->toContain('k9s_Darwin_arm64.tar.gz')
        ->toContain("tar -xz -C '/tmp/test-bin' k9s")
        ->not->toContain('sudo');

    $linuxCmd = CliTool::k9sInstallCommand('linux', 'amd64', '/tmp/test-bin');
    expect($linuxCmd)->toContain('k9s_Linux_amd64.tar.gz')
        ->not->toContain('sudo');
});

test('tofu install command resolves latest release and extracts into binDir without sudo', function (): void {
    $cmd = CliTool::tofuInstallCommand('darwin', 'arm64', '/tmp/test-bin');

    expect($cmd)->toContain('https://github.com/opentofu/opentofu/releases/latest')
        ->toContain('tofu_${V}_darwin_arm64.tar.gz')
        ->toContain("tar -xz -C '/tmp/test-bin' tofu")
        ->not->toContain('sudo')
        ->not->toContain('unzip');
});

test('aws install command runs official v2 user-local installer with XDG_BIN_HOME', function (): void {
    $cmd = CliTool::awsInstallCommand('/tmp/test-bin');

    expect($cmd)->toContain('https://awscli.amazonaws.com/v2/install.sh')
        ->toContain("XDG_BIN_HOME='/tmp/test-bin'")
        ->not->toContain('sudo');
});

test('hcloud install command downloads archive for darwin and linux', function (): void {
    $darwinCmd = CliTool::hcloudInstallCommand('darwin', 'arm64', '/tmp/test-bin');
    expect($darwinCmd)->toContain('hcloud-darwin-arm64.tar.gz')
        ->toContain("tar -xz -C '/tmp/test-bin' hcloud")
        ->not->toContain('sudo');

    $linuxCmd = CliTool::hcloudInstallCommand('linux', 'amd64', '/tmp/test-bin');
    expect($linuxCmd)->toContain('hcloud-linux-amd64.tar.gz')
        ->not->toContain('sudo');
});

test('gh install command supports macOS using zip and junk-paths unzip', function (): void {
    $cmd = CliTool::ghInstallCommand('arm64', '/tmp/test-bin', 'darwin');

    expect($cmd)->toContain('https://github.com/cli/cli/releases/latest')
        ->toContain('D="gh_${V}_macOS_arm64"')
        ->toContain('${D}.zip')
        ->toContain('unzip -q -j "$T/gh.zip" "${D}/bin/gh" -d \'/tmp/test-bin\'')
        ->not->toContain('sudo');
});

test('tea install command supports macOS darwin binary', function (): void {
    $cmd = CliTool::teaInstallCommand('arm64', '/tmp/test-bin/tea', 'darwin');

    expect($cmd)->toContain('tea-${V}-darwin-arm64')
        ->not->toContain('sudo');
});
