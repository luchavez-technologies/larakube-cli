<?php

namespace App\Traits;

use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;

/**
 * Drives OpenTofu (or a Terraform fallback) for the `cloud:create` / `cloud:destroy`
 * flows. OpenTofu is treated as a NATIVE host binary (like kubectl), not a
 * containerized tool — it's a single static Go binary, and being stateful (state +
 * provider-plugin cache) it fits a host install far better than the Docker-wrapped
 * `gh` pattern.
 *
 * Storage is GLOBAL and per-stack: ~/.larakube/tofu/<stack>/ holds the rendered HCL
 * plus state, so multiple projects/environments can share one stack. With OpenTofu,
 * state is encrypted at rest (PBKDF2 passphrase from the global config, injected via
 * TF_ENCRYPTION so it never lands in committed HCL). Terraform has no native
 * encryption, so its state stays plaintext in the same machine-local dir.
 */
trait InteractsWithOpenTofu
{
    use DetectsWsl, InteractsWithGlobalConfig, InteractsWithOs, StreamsProcessOutput;

    /**
     * Resolve a native tofu/terraform binary. Prefers OpenTofu; falls back to
     * Terraform (the same HCL runs on either). Returns ['path' => ..., 'isOpenTofu'
     * => bool] or null when neither is installed.
     *
     * @return array{path: string, isOpenTofu: bool}|null
     */
    protected function resolveTofuBinary(): ?array
    {
        $candidates = [
            ['bin' => 'tofu', 'isOpenTofu' => true],
            ['bin' => 'terraform', 'isOpenTofu' => false],
        ];

        $dirs = ['', '/usr/local/bin/', '/opt/homebrew/bin/', '/home/linuxbrew/.linuxbrew/bin/'];

        foreach ($candidates as $c) {
            // PATH lookup first, then common install dirs (non-interactive shells
            // often miss Homebrew/linuxbrew paths — same issue getGhCommand guards).
            $which = trim(Process::run('command -v '.$c['bin'])->output());
            if ($which !== '' && @is_executable($which)) {
                return ['path' => $which, 'isOpenTofu' => $c['isOpenTofu']];
            }
            foreach ($dirs as $dir) {
                $path = $dir.$c['bin'];
                if ($dir !== '' && @is_executable($path)) {
                    return ['path' => $path, 'isOpenTofu' => $c['isOpenTofu']];
                }
            }
        }

        return null;
    }

    /**
     * Resolve the binary, offering to install OpenTofu when nothing is found.
     * Returns the resolved binary info or null if still unavailable (caller errors).
     *
     * @return array{path: string, isOpenTofu: bool}|null
     */
    protected function ensureTofu(): ?array
    {
        if ($bin = $this->resolveTofuBinary()) {
            return $bin;
        }

        $this->laraKubeWarn('OpenTofu (or Terraform) was not found on your PATH.');

        if ($this->offerTofuInstall()) {
            return $this->resolveTofuBinary();
        }

        $this->laraKubeError('OpenTofu is required. Install it: https://opentofu.org/docs/intro/install/');

        return null;
    }

    /**
     * Offer a platform-appropriate native install. Never forced — we prompt, then
     * stream the official installer. macOS uses Homebrew; Linux/WSL2 uses the
     * official standalone installer (needs sudo).
     */
    protected function offerTofuInstall(): bool
    {
        if ($this->isDarwin()) {
            $brew = trim(Process::run('command -v brew')->output());
            if ($brew === '') {
                $this->laraKubeWarn('Homebrew not found — install OpenTofu manually: https://opentofu.org/docs/intro/install/');

                return false;
            }
            if (! confirm('Install OpenTofu now via Homebrew (brew install opentofu)?', true)) {
                return false;
            }

            return $this->runStreaming('brew install opentofu') === 0;
        }

        if ($this->isLinux()) {
            $where = $this->isWsl() ? 'WSL2' : 'Linux';
            $this->laraKubeInfo("Detected {$where}. The official installer needs sudo.");
            if (! confirm('Install OpenTofu now via the official install script (curl … | sudo bash)?', true)) {
                return false;
            }
            if (! $this->ensureUnzip()) {
                return false;
            }
            // A hardcoded /tmp path here would let any local user race it with a
            // symlink before `sudo` executes it — tempnam() picks an
            // unpredictable name so there's nothing to pre-place.
            $scriptPath = (string) tempnam(sys_get_temp_dir(), 'larakube_opentofu_install');

            // Official standalone installer — picks deb/rpm/standalone automatically.
            $script = 'curl -fsSL https://get.opentofu.org/install-opentofu.sh -o '.escapeshellarg($scriptPath)
                .' && chmod +x '.escapeshellarg($scriptPath)
                .' && sudo '.escapeshellarg($scriptPath).' --install-method standalone'
                .'; rm -f '.escapeshellarg($scriptPath);
            passthru($script, $code);

            return $code === 0;
        }

        $this->laraKubeWarn('Automatic install is unavailable on this OS. See https://opentofu.org/docs/intro/install/');

        return false;
    }

    /**
     * The OpenTofu install script's `--install-method standalone` path shells out
     * to `unzip` to extract the release archive and dies with a bare "unzip is
     * required" if it's missing. Fix that ourselves rather than let the
     * third-party script fail with an unhelpful mid-stream error.
     */
    protected function ensureUnzip(): bool
    {
        if (trim(Process::run('command -v unzip')->output()) !== '') {
            return true;
        }

        $this->laraKubeWarn('unzip is required by the OpenTofu installer but is not installed.');
        if (file_exists('/usr/bin/apt-get')) {
            passthru('sudo apt-get update -y && sudo apt-get install -y unzip', $code);
        } elseif (file_exists('/usr/bin/dnf')) {
            passthru('sudo dnf install -y unzip', $code);
        } elseif (file_exists('/usr/bin/pacman')) {
            passthru('sudo pacman -Sy --noconfirm unzip', $code);
        } else {
            $this->laraKubeError('Could not detect a package manager. Install unzip manually, then re-run.');

            return false;
        }

        return $code === 0;
    }

    /** The global per-stack Tofu working dir, created (0700) on demand. */
    protected function tofuWorkdir(string $stack): string
    {
        $dir = home_path('.larakube/tofu/'.$stack);
        if (! is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        return $dir;
    }

    /**
     * Write rendered HCL files into the stack workdir.
     *
     * @param  array<string, string>  $files  filename => contents (e.g. 'main.tf' => '…')
     */
    protected function writeTofuFiles(string $stack, array $files): string
    {
        $dir = $this->tofuWorkdir($stack);
        foreach ($files as $name => $contents) {
            file_put_contents($dir.'/'.$name, $contents);
        }

        return $dir;
    }

    /** True once a stack has real state (i.e. it has been applied at least once). */
    protected function tofuStateExists(string $stack): bool
    {
        $state = $this->tofuWorkdir($stack).'/terraform.tfstate';

        return file_exists($state) && filesize($state) > 0;
    }

    /**
     * The OpenTofu state-encryption config for TF_ENCRYPTION (PBKDF2). Empty string
     * for Terraform (no native encryption) — its state stays plaintext on disk.
     */
    protected function tofuEncryptionEnv(string $stack, bool $isOpenTofu): string
    {
        if (! $isOpenTofu) {
            return '';
        }

        // Load ONCE, mint-if-missing, persist, and use that same value — getGlobalConfig()
        // reloads from disk each call, so reusing one instance avoids minting two
        // different random passphrases (returning one while saving another).
        $config = $this->getGlobalConfig();
        $passphrase = $config->ensureTofuPassphrase($stack);
        $config->save();

        return <<<HCL
key_provider "pbkdf2" "larakube" {
  passphrase = "{$passphrase}"
}
method "aes_gcm" "larakube" {
  keys = key_provider.pbkdf2.larakube
}
state {
  method = method.aes_gcm.larakube
}
plan {
  method = method.aes_gcm.larakube
}
HCL;
    }

    /**
     * Build the `KEY='val' …` env prefix for a Tofu invocation: the DO token plus
     * (OpenTofu only) the encryption config. Extra per-call vars merge in.
     *
     * @param  array<string, string>  $extra
     */
    protected function tofuEnvPrefix(string $stack, bool $isOpenTofu, array $extra = []): string
    {
        $env = [];

        if ($token = $this->getDoToken()) {
            $env['TF_VAR_do_token'] = $token;
        }

        $encryption = $this->tofuEncryptionEnv($stack, $isOpenTofu);
        if ($encryption !== '') {
            $env['TF_ENCRYPTION'] = $encryption;
        }

        $env = array_merge($env, $extra);

        // Non-interactive provider installs; never prompt for input mid-run.
        $env['TF_IN_AUTOMATION'] = '1';

        $prefix = '';
        foreach ($env as $k => $v) {
            $prefix .= $k.'='.escapeshellarg($v).' ';
        }

        return $prefix;
    }

    /**
     * Run a tofu subcommand against a stack workdir (via -chdir, so no `cd`).
     * Streams output by default; set $capture to return trimmed stdout instead.
     *
     * @param  array<int, string>  $args
     * @param  array<string, string>  $env
     */
    protected function runTofu(array $bin, string $stack, string $subcommand, array $args = [], array $env = [], bool $capture = false): array
    {
        $dir = $this->tofuWorkdir($stack);
        $prefix = $this->tofuEnvPrefix($stack, $bin['isOpenTofu'], $env);
        $cmd = $prefix.escapeshellarg($bin['path']).' -chdir='.escapeshellarg($dir).' '.$subcommand;
        foreach ($args as $a) {
            $cmd .= ' '.$a;
        }

        if ($capture) {
            $result = Process::run($cmd);

            return ['code' => $result->exitCode(), 'output' => trim($result->output())];
        }

        $code = $this->runStreaming($cmd);

        return ['code' => $code, 'output' => ''];
    }

    /** `tofu init` — downloads the provider plugins into the stack workdir. */
    protected function tofuInit(array $bin, string $stack): bool
    {
        return $this->runTofu($bin, $stack, 'init', ['-input=false'])['code'] === 0;
    }

    /** `tofu apply` — creates/updates infra. Auto-approve by default (we confirm in the command). */
    protected function tofuApply(array $bin, string $stack, bool $autoApprove = true): bool
    {
        $args = ['-input=false'];
        if ($autoApprove) {
            $args[] = '-auto-approve';
        }

        return $this->runTofu($bin, $stack, 'apply', $args)['code'] === 0;
    }

    /** `tofu destroy` — tears the stack down. */
    protected function tofuDestroy(array $bin, string $stack, bool $autoApprove = true): bool
    {
        $args = ['-input=false'];
        if ($autoApprove) {
            $args[] = '-auto-approve';
        }

        return $this->runTofu($bin, $stack, 'destroy', $args)['code'] === 0;
    }

    /** Read a single `output -raw <key>` value, or null when unavailable. */
    protected function tofuOutput(array $bin, string $stack, string $key): ?string
    {
        $res = $this->runTofu($bin, $stack, 'output', ['-raw', escapeshellarg($key)], [], capture: true);

        return ($res['code'] === 0 && $res['output'] !== '') ? $res['output'] : null;
    }
}
