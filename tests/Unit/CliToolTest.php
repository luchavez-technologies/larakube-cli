<?php

use App\Enums\CliTool;
use App\Support\Os;
use Illuminate\Support\Facades\Http;
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

test('install() self-heals a Homebrew permission error and retries once', function (): void {
    // Confirmed live (2026-10-24): a single `brew install --cask gcloud-cli`
    // printed BOTH an early one-line "<path> is not writable... sudo chown -R
    // <user> <path>" warning AND, once dependency resolution reached
    // directories that warning never covered, a second, FATAL "The following
    // directories are not writable by your user:" block listing several more
    // paths with NO suggested command of its own — proving an earlier version
    // of this fix (which only ever parsed a "sudo chown -R" line) chowned too
    // little and the retry failed the exact same way.
    $homebrewError = <<<'OUTPUT'
        Error: /opt/homebrew is not writable. You should change the ownership and permissions of /opt/homebrew back to your
        user account:
          sudo chown -R someone /opt/homebrew
        ==> Downloading JSON API packages.arm64_sonoma.jws.json
        ==> Would install 1 cask:
          gcloud-cli
        ==> Would install 7 dependencies for gcloud-cli:
          python@3.13 ca-certificates openssl@3 readline sqlite xz
        Error: The following directories are not writable by your user:
        /opt/homebrew
        /opt/homebrew/etc/bash_completion.d
        /opt/homebrew/share/doc
        /opt/homebrew/share/man
        /opt/homebrew/share/man/man1
        /opt/homebrew/share/zsh
        /opt/homebrew/share/zsh/site-functions
        /opt/homebrew/var/homebrew/locks
        OUTPUT;

    $installed = false;

    Process::fake([
        'command -v gcloud' => function () use (&$installed) {
            return $installed ? Process::result('/opt/homebrew/bin/gcloud') : Process::result('', exitCode: 1);
        },
        'command -v brew' => Process::result('/opt/homebrew/bin/brew'),
        'brew install --cask gcloud-cli || brew install --cask google-cloud-sdk' => function () use (&$installed, $homebrewError) {
            if ($installed) {
                return Process::result('==> gcloud-cli was already installed!', exitCode: 0);
            }
            $installed = true;

            return Process::result($homebrewError, exitCode: 1);
        },
        '*' => Process::result(''),
    ]);

    expect(CliTool::GCLOUD->install())->toBeTrue();

    // Every path from both message shapes is chowned in one pass, using our
    // own $(whoami) rather than whatever owner token Homebrew's own line used.
    Process::assertRan(fn ($process) => $process->command === "sudo -n chown -R \$(whoami) '/opt/homebrew' '/opt/homebrew/etc/bash_completion.d' "
        ."'/opt/homebrew/share/doc' '/opt/homebrew/share/man' '/opt/homebrew/share/man/man1' '/opt/homebrew/share/zsh' "
        ."'/opt/homebrew/share/zsh/site-functions' '/opt/homebrew/var/homebrew/locks'");

    Process::assertRanTimes('brew install --cask gcloud-cli || brew install --cask google-cloud-sdk', 2);
})->skip(PHP_OS_FAMILY !== 'Darwin', '`brew install --cask` is macOS-only; installGcloud() only takes this branch on Darwin.');

test('install() self-heals when Homebrew only prints the bare directory-list format, with no suggested command at all', function (): void {
    // The fatal block alone, with no leading "<path> is not writable" one-liner
    // and no "sudo chown -R" line anywhere — confirmed live (2026-10-24) this
    // can be the ENTIRE failure, not just a tail end of the mixed case above.
    $homebrewError = <<<'OUTPUT'
        Error: The following directories are not writable by your user:
        /opt/homebrew
        /opt/homebrew/share/zsh/site-functions
        OUTPUT;

    $installed = false;

    Process::fake([
        'command -v gcloud' => function () use (&$installed) {
            return $installed ? Process::result('/opt/homebrew/bin/gcloud') : Process::result('', exitCode: 1);
        },
        'command -v brew' => Process::result('/opt/homebrew/bin/brew'),
        'brew install --cask gcloud-cli || brew install --cask google-cloud-sdk' => function () use (&$installed, $homebrewError) {
            if ($installed) {
                return Process::result('==> gcloud-cli was already installed!', exitCode: 0);
            }
            $installed = true;

            return Process::result($homebrewError, exitCode: 1);
        },
        '*' => Process::result(''),
    ]);

    expect(CliTool::GCLOUD->install())->toBeTrue();

    Process::assertRan(fn ($process) => $process->command === "sudo -n chown -R \$(whoami) '/opt/homebrew' '/opt/homebrew/share/zsh/site-functions'");
})->skip(PHP_OS_FAMILY !== 'Darwin', '`brew install --cask` is macOS-only; installGcloud() only takes this branch on Darwin.');

test('install() does not retry a brew failure unrelated to Homebrew permissions', function (): void {
    Process::fake([
        'command -v gcloud' => Process::result('', exitCode: 1),
        'command -v brew' => Process::result('/opt/homebrew/bin/brew'),
        'brew install --cask gcloud-cli || brew install --cask google-cloud-sdk' => Process::result('Error: Cask \'gcloud-cli\' is unavailable', exitCode: 1),
        '*' => Process::result(''),
    ]);

    expect(CliTool::GCLOUD->install())->toBeFalse();

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'sudo -n chown'));
    Process::assertRanTimes('brew install --cask gcloud-cli || brew install --cask google-cloud-sdk', 1);
})->skip(PHP_OS_FAMILY !== 'Darwin', '`brew install --cask` is macOS-only; installGcloud() only takes this branch on Darwin.');

test('homebrewUnwritablePaths extracts every path across both message shapes, run cross-platform since the Darwin-only install() tests above cannot be', function (): void {
    $method = new ReflectionMethod(CliTool::class, 'homebrewUnwritablePaths');
    $method->setAccessible(true);

    $mixed = <<<'OUTPUT'
        Error: /opt/homebrew is not writable. You should change the ownership and permissions of /opt/homebrew back to your
        user account:
          sudo chown -R someone /opt/homebrew
        Error: The following directories are not writable by your user:
        /opt/homebrew
        /opt/homebrew/share/doc
        OUTPUT;

    expect($method->invoke(CliTool::GCLOUD, $mixed))->toBe(['/opt/homebrew', '/opt/homebrew/share/doc']);

    $bareListOnly = <<<'OUTPUT'
        Error: The following directories are not writable by your user:
        /opt/homebrew
        /opt/homebrew/share/zsh/site-functions
        OUTPUT;

    expect($method->invoke(CliTool::GCLOUD, $bareListOnly))->toBe(['/opt/homebrew', '/opt/homebrew/share/zsh/site-functions']);

    $unrelatedFailure = "Error: Cask 'gcloud-cli' is unavailable";

    expect($method->invoke(CliTool::GCLOUD, $unrelatedFailure))->toBe([]);
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

test('installGcloud() falls back to a standalone Python build when there is no Homebrew and no supported interpreter on PATH', function (): void {
    // Confirmed live: python.org's own macOS installer needs sudo (a system-
    // wide .pkg), which Desktop's GUI "Install" button has no terminal to
    // satisfy — so without Homebrew, resolvePython()/installPython() must
    // reach a working interpreter some other, no-elevation way.
    $installed = false;
    $standaloneBin = home_path('.larakube/python-standalone').'/bin/python3';

    Process::fake([
        'command -v gcloud' => function () use (&$installed) {
            return $installed
                ? Process::result('/Users/dev/.larakube/google-cloud-sdk/bin/gcloud')
                : Process::result('', exitCode: 1);
        },
        'command -v brew' => Process::result('', exitCode: 1),
        'command -v python*' => Process::result('', exitCode: 1),
        '*python-build-standalone*' => function () use ($standaloneBin) {
            @mkdir(dirname($standaloneBin), 0755, true);
            file_put_contents($standaloneBin, "#!/bin/sh\n");
            @chmod($standaloneBin, 0755);

            return Process::result('', exitCode: 0);
        },
        escapeshellarg($standaloneBin).' -c *' => Process::result('3.12'),
        '*sdk.cloud.google.com*' => function () use (&$installed) {
            $installed = true;

            return Process::result('', exitCode: 0);
        },
    ]);

    expect(CliTool::GCLOUD->install())->toBeTrue();

    Process::assertRan(fn ($process) => str_contains($process->command, 'python-build-standalone')
        && str_contains($process->command, 'apple-darwin-install_only'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'sdk.cloud.google.com')
        && ($process->environment['CLOUDSDK_PYTHON'] ?? null) === $standaloneBin);
})->skip(PHP_OS_FAMILY !== 'Darwin', 'installGcloud()/installPython() only take the Darwin standalone-Python branch on Darwin.');

test('update() upgrades via brew when the tool is brew-managed', function (): void {
    Process::fake([
        'command -v k9s' => Process::result('/opt/homebrew/bin/k9s'),
        '*' => Process::result(''),
    ]);

    CliTool::K9S->update();

    Process::assertRan(fn ($process) => str_contains($process->command, 'brew upgrade k9s'));
});

test('update() re-runs the direct-download installer for a tool Homebrew never touched', function (): void {
    Process::fake([
        'command -v tofu' => Process::result('/Users/dev/.larakube/bin/tofu'),
        '*' => Process::result(''),
    ]);

    CliTool::TOFU->update();

    Process::assertRan(fn ($process) => str_contains($process->command, 'opentofu/releases/latest'));
});

test('update() appends --update to the AWS CLI\'s own installer instead of reinstalling from scratch', function (): void {
    Process::fake([
        'command -v aws' => Process::result('/Users/dev/.larakube/bin/aws'),
        '*' => Process::result(''),
    ]);

    CliTool::AWS->update();

    Process::assertRan(fn ($process) => str_contains($process->command, 'install.sh | XDG_BIN_HOME=')
        && str_contains($process->command, 'bash -s -- --update'));
});

test('update() asks the Google Cloud SDK to update itself rather than re-running its installer', function (): void {
    Process::fake([
        'command -v gcloud' => Process::result('/Users/dev/.larakube/google-cloud-sdk/bin/gcloud'),
        'command -v python3.12' => Process::result('/usr/local/bin/python3.12'),
        '*python3.12* -c *' => Process::result('3.12'),
        '*' => Process::result(''),
    ]);

    CliTool::GCLOUD->update();

    Process::assertRan(fn ($process) => str_contains($process->command, 'components update --quiet')
        && ! str_contains($process->command, 'sdk.cloud.google.com'));
});

test('update() hands the Google Cloud SDK updater its own CLOUDSDK_PYTHON, same as install', function (): void {
    // `components update` re-runs the same launcher install does, so a
    // non-Homebrew install (no cask-managed Python pinned for it) hits the
    // same broken-system-Python discovery otherwise — reported live on a
    // Mac without Homebrew ("you are running gcloud with Python 3.9").
    Process::fake([
        'command -v gcloud' => Process::result('/Users/dev/.larakube/google-cloud-sdk/bin/gcloud'),
        'command -v python3.12' => Process::result('/usr/local/bin/python3.12'),
        '*python3.12* -c *' => Process::result('3.12'),
        '*' => Process::result(''),
    ]);

    CliTool::GCLOUD->update();

    Process::assertRan(fn ($process) => str_contains($process->command, 'components update --quiet')
        && ($process->environment['CLOUDSDK_PYTHON'] ?? null) === '/usr/local/bin/python3.12');
});

test('update() refuses to run the Google Cloud SDK updater against an unsupported Python', function (): void {
    Process::fake([
        'command -v gcloud' => Process::result('/Users/dev/.larakube/google-cloud-sdk/bin/gcloud'),
        'command -v python3' => Process::result('/usr/bin/python3'),
        '*python3* -c *' => Process::result('3.9'),
        '*' => Process::result('', exitCode: 1),
    ]);

    expect(CliTool::GCLOUD->update())->toBeFalse();

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'components update --quiet'));
});

test('update() installs a missing tool instead of trying to upgrade nothing', function (): void {
    Process::fake([
        'command -v hcloud' => Process::result('', exitCode: 1),
        '*' => Process::result(''),
    ]);

    CliTool::HCLOUD->update();

    Process::assertRan(fn ($process) => str_contains($process->command, 'hetznercloud/cli/releases/latest'));
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

// --- Windows native installers (ADR 0007's WSL path stays for the CLI's own
// orchestration commands; these cover only the tool-installer layer) ---

afterEach(function (): void {
    Os::setForcedFamily(null);
    putenv('LARAKUBE_BIN_DIR');
    putenv('HOME');
    putenv('LOCALAPPDATA');
});

/**
 * Builds a zip archive in memory (via a throwaway temp file, since
 * ZipArchive has no fully in-memory mode) and returns its raw bytes, for
 * Http::fake() to serve as a release asset's response body.
 */
function fakeZipBytes(array $entries): string
{
    $path = tempnam(sys_get_temp_dir(), 'larakube-test-zip-');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    foreach ($entries as $name => $contents) {
        $zip->addFromString($name, $contents);
    }
    $zip->close();

    $bytes = (string) file_get_contents($path);
    unlink($path);

    return $bytes;
}

test('binary() and candidatePaths() carry Windows-specific names, not the Darwin/Linux ones', function (): void {
    Os::setForcedFamily('Windows');

    expect(CliTool::KUBECTL->binary())->toBe('kubectl.exe')
        ->and(CliTool::K9S->binary())->toBe('k9s.exe')
        ->and(CliTool::TOFU->binary())->toBe('tofu.exe')
        ->and(CliTool::AWS->binary())->toBe('aws.exe')
        ->and(CliTool::GCLOUD->binary())->toBe('gcloud.cmd')
        ->and(CliTool::KUBECTL->candidatePaths())->toContain(CliTool::binDir().'/kubectl.exe');
});

test('install() downloads kubectl.exe directly with no WSL and no shell one-liner', function (): void {
    Os::setForcedFamily('Windows');
    $dir = Spatie\TemporaryDirectory\TemporaryDirectory::make()->deleteWhenDestroyed();
    putenv('LARAKUBE_BIN_DIR='.$dir->path());

    Http::fake([
        'https://dl.k8s.io/release/stable.txt' => Http::response('v1.31.0'),
        'https://dl.k8s.io/release/v1.31.0/bin/windows/amd64/kubectl.exe' => Http::response('fake-kubectl-binary'),
    ]);

    expect(CliTool::KUBECTL->install())->toBeTrue()
        ->and(file_get_contents($dir->path().'/kubectl.exe'))->toBe('fake-kubectl-binary');
});

test('install() extracts k9s.exe out of the Windows release zip', function (): void {
    Os::setForcedFamily('Windows');
    $dir = Spatie\TemporaryDirectory\TemporaryDirectory::make()->deleteWhenDestroyed();
    putenv('LARAKUBE_BIN_DIR='.$dir->path());

    Http::fake([
        'https://github.com/derailed/k9s/releases/latest/download/k9s_Windows_amd64.zip' => Http::response(
            fakeZipBytes(['k9s.exe' => 'fake-k9s-binary']),
        ),
    ]);

    expect(CliTool::K9S->install())->toBeTrue()
        ->and(file_get_contents($dir->path().'/k9s.exe'))->toBe('fake-k9s-binary');
});

test('install() resolves the current OpenTofu release before downloading the versioned Windows zip', function (): void {
    Os::setForcedFamily('Windows');
    $dir = Spatie\TemporaryDirectory\TemporaryDirectory::make()->deleteWhenDestroyed();
    putenv('LARAKUBE_BIN_DIR='.$dir->path());

    Http::fake([
        'https://api.github.com/repos/opentofu/opentofu/releases/latest' => Http::response(['tag_name' => 'v1.8.0']),
        'https://github.com/opentofu/opentofu/releases/download/v1.8.0/tofu_1.8.0_windows_amd64.zip' => Http::response(
            fakeZipBytes(['tofu.exe' => 'fake-tofu-binary']),
        ),
    ]);

    expect(CliTool::TOFU->install())->toBeTrue()
        ->and(file_get_contents($dir->path().'/tofu.exe'))->toBe('fake-tofu-binary');
});

test('install() uses AWS\'s documented no-admin per-user MSI, never the all-users one', function (): void {
    Os::setForcedFamily('Windows');
    $appData = Spatie\TemporaryDirectory\TemporaryDirectory::make()->deleteWhenDestroyed();
    putenv('LOCALAPPDATA='.$appData->path());
    $target = $appData->path().'/Programs/Amazon/AWSCLIV2/aws.exe';

    Http::fake([
        'https://awscli.amazonaws.com/AWSCLIV2-User.msi' => Http::response('fake-msi-bytes'),
    ]);

    Process::fake([
        // Stands in for the real MSI actually placing a (Windows-executable)
        // aws.exe at this fixed path — chmod matches what the real download
        // helpers do after every write, since is_executable() on the real
        // POSIX test runner otherwise sees a freshly-written 0644 file as
        // not executable, unlike how Windows treats a plain .exe.
        'msiexec.exe*' => function () use ($target) {
            @mkdir(dirname($target), 0755, true);
            file_put_contents($target, 'fake-aws-binary');
            @chmod($target, 0755);

            return Process::result('', exitCode: 0);
        },
    ]);

    expect(CliTool::AWS->install())->toBeTrue();

    Process::assertRan(fn ($process) => str_contains($process->command, '/qn')
        && ! str_contains($process->command, 'AWSCLIV2.msi '));
});

test('a failed AWS CLI install surfaces the real msiexec exit code via lastError(), not just a bare false', function (): void {
    // Confirmed live (2026-10-30): runStreaming() kept only the bare exit
    // code and /qn alone gave msiexec nothing to log, so every failure here
    // collapsed to the same uninformative "Failed to install AWS CLI" with
    // no way to tell an environment block from a transient network error.
    Os::setForcedFamily('Windows');
    $appData = Spatie\TemporaryDirectory\TemporaryDirectory::make()->deleteWhenDestroyed();
    putenv('LOCALAPPDATA='.$appData->path());

    Http::fake([
        'https://awscli.amazonaws.com/AWSCLIV2-User.msi' => Http::response('fake-msi-bytes'),
    ]);

    Process::fake([
        'msiexec.exe*' => Process::result('', exitCode: 1603),
    ]);

    expect(CliTool::AWS->install())->toBeFalse()
        ->and(CliTool::AWS->lastError())->toContain('1603');
});

test('install() clears a previous failure\'s lastError() once it later succeeds', function (): void {
    Os::setForcedFamily('Windows');
    $appData = Spatie\TemporaryDirectory\TemporaryDirectory::make()->deleteWhenDestroyed();
    putenv('LOCALAPPDATA='.$appData->path());
    $target = $appData->path().'/Programs/Amazon/AWSCLIV2/aws.exe';

    Http::fake([
        'https://awscli.amazonaws.com/AWSCLIV2-User.msi' => Http::response('fake-msi-bytes'),
    ]);

    Process::fake([
        'msiexec.exe*' => Process::result('', exitCode: 1603),
    ]);

    expect(CliTool::AWS->install())->toBeFalse()
        ->and(CliTool::AWS->lastError())->not->toBeNull();

    Process::fake([
        'msiexec.exe*' => function () use ($target) {
            @mkdir(dirname($target), 0755, true);
            file_put_contents($target, 'fake-aws-binary');
            @chmod($target, 0755);

            return Process::result('', exitCode: 0);
        },
    ]);

    expect(CliTool::AWS->install())->toBeTrue()
        ->and(CliTool::AWS->lastError())->toBeNull();
});

test('httpDownloadToFile downloads to a sibling temp file and only the final path exists once done', function (): void {
    // Confirmed live (2026-10-30): streaming straight onto a live binary's
    // own path left it open/partially-written for the whole download, and
    // a transient lock on it (AV scanning the fresh exe, a stray process)
    // could fail the write — or the isInstalled() re-check right after —
    // even though the file was fine moments later. Writing to a sibling
    // temp file and rename()ing it into place removes that window.
    Os::setForcedFamily('Windows');
    $dir = Spatie\TemporaryDirectory\TemporaryDirectory::make()->deleteWhenDestroyed();
    putenv('LARAKUBE_BIN_DIR='.$dir->path());

    Http::fake([
        'https://dl.k8s.io/release/stable.txt' => Http::response('v1.31.0'),
        'https://dl.k8s.io/release/v1.31.0/bin/windows/amd64/kubectl.exe' => Http::response('fake-kubectl-binary'),
    ]);

    expect(CliTool::KUBECTL->install())->toBeTrue()
        ->and(file_get_contents($dir->path().'/kubectl.exe'))->toBe('fake-kubectl-binary')
        ->and(file_exists($dir->path().'/kubectl.exe.download'))->toBeFalse();
});

test('install() fails the Google Cloud SDK on Windows rather than leaving a non-functional install when no Python is found', function (): void {
    Os::setForcedFamily('Windows');
    // TestCase::setUp() already sandboxes $_SERVER['HOME'] to a fresh
    // per-test temp dir — home_path() reads THAT first, ahead of
    // getenv('HOME'), so that's the directory to assert against, not a
    // separately putenv'd one.
    putenv('LOCALAPPDATA='.$_SERVER['HOME'].'/AppData/Local');

    // resolvePython()'s `command -v pythonX.Y` loop runs unconditionally,
    // on every OS (it's only the Windows-specific glob fallback below it
    // that's gated) — without faking this, the test's outcome depends on
    // whether the machine actually running it happens to expose a real
    // Python 3.10+ via `command -v`, which varies by host (confirmed live:
    // passed locally, failed in CI, for exactly this reason).
    Process::fake([
        'command -v python*' => Process::result('', exitCode: 1),
    ]);

    Http::fake([
        'https://dl.google.com/dl/cloudsdk/channels/rapid/downloads/google-cloud-cli-windows-x86_64.zip' => Http::response(
            fakeZipBytes(['google-cloud-sdk/bin/gcloud.cmd' => 'fake-gcloud-wrapper']),
        ),
    ]);

    expect(CliTool::GCLOUD->install())->toBeFalse()
        ->and(file_exists($_SERVER['HOME'].'/google-cloud-sdk/bin/gcloud.cmd'))->toBeTrue();
});
