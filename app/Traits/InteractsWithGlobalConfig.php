<?php

namespace App\Traits;

use App\Data\GlobalConfigData;
use App\Enums\AiProvider;
use App\State;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

trait InteractsWithGlobalConfig
{
    use InteractsWithOs;

    protected function getGlobalConfig(): GlobalConfigData
    {
        return GlobalConfigData::load();
    }

    protected function getGhConfigPath(): string
    {
        return home_path('.larakube/gh-config');
    }

    protected function getGhCommand(?string $workDir = null, bool $interactive = false): string
    {
        // command -v uses the non-interactive shell PATH which may miss tools
        // installed by Homebrew or similar. Check common locations as a fallback.
        $candidates = array_filter([
            trim(Process::run('command -v gh')->output()),
            '/usr/local/bin/gh',
            '/opt/homebrew/bin/gh',
            '/home/linuxbrew/.linuxbrew/bin/gh',
        ]);

        foreach ($candidates as $path) {
            if ($path !== '' && @is_executable($path)) {
                return $path;
            }
        }

        // Fall back to running gh inside a throw-away Docker container.
        return $this->getGhDockerCommand($workDir, $interactive);
    }

    protected function getGhDockerCommand(?string $workDir = null, bool $interactive = false): string
    {
        $workDir = $workDir ?? getcwd();
        $ghConfigPath = $this->getGhConfigPath();
        $dockerConfigPath = home_path('.docker');

        if (! is_dir($ghConfigPath)) {
            @mkdir($ghConfigPath, 0700, true);
        }

        $mounts = [
            "-v {$workDir}:/work",
            "-v {$ghConfigPath}:/root/.config/gh",
        ];

        // Mount host docker config if it exists to share registry credentials (solves GHCR 403s)
        if (is_dir($dockerConfigPath)) {
            $mounts[] = "-v {$dockerConfigPath}:/root/.docker:ro";
        }

        $mountString = implode(' ', $mounts);
        // We always include -i to support piping data (like secrets) into the container
        $interactiveFlag = $interactive ? '-it' : '-i';

        return "docker run --rm {$interactiveFlag} {$mountString} -w /work alpine:latest sh -c 'apk add --no-cache github-cli >/dev/null && gh \"\$@\"' larakube-gh ";
    }

    protected function getEmail(): ?string
    {
        return $this->getGlobalConfig()->getEmail();
    }

    protected function getDefaultEmail(): string
    {
        return 'admin@example.com';
    }

    protected function setEmail(string $email): void
    {
        $config = $this->getGlobalConfig();
        $config->setEmail($email);
        $config->save();
    }

    /**
     * RFC-compliant + a live MX check — rejects domains that explicitly
     * refuse mail (RFC 7505 "Null MX", which example.com/.net/.org publish),
     * which plain FILTER_VALIDATE_EMAIL lets through since they're
     * syntactically valid. Used as the ACME contact for Let's Encrypt, which
     * rejects those same domains outright.
     */
    protected function acmeEmailError(string $value): ?string
    {
        $validator = Validator::make(
            ['email' => $value],
            ['email' => ['required', Rule::email()->rfcCompliant()->validateMxRecord()]],
        );

        return $validator->fails() ? $validator->errors()->first('email') : null;
    }

    /** A stored email, but only if it'd actually pass ACME validation — else null, forcing a fresh prompt. */
    protected function validStoredEmail(?string $email): ?string
    {
        return $email && ! $this->acmeEmailError($email) ? $email : null;
    }

    protected function getDefaultCloudProvider(): string
    {
        return $this->getGlobalConfig()->getDefaultCloudProvider() ?: 'do';
    }

    protected function setDefaultCloudProvider(string $provider): void
    {
        $config = $this->getGlobalConfig();
        $config->setDefaultCloudProvider($provider);
        $config->save();
    }

    protected function getDoToken(): ?string
    {
        // A run-only token (--do-token / TF_VAR_do_token on cloud:create)
        // wins over the persisted one and never touches disk.
        return State::$transientDoToken ?? $this->getGlobalConfig()->getDoToken();
    }

    protected function setDoToken(?string $token): void
    {
        $config = $this->getGlobalConfig();
        $config->setDoToken($token);
        $config->save();
    }

    protected function getLocalTld(): string
    {
        return $this->getGlobalConfig()->getLocalTld();
    }

    protected function setLocalTld(string $tld): void
    {
        $config = $this->getGlobalConfig();
        $config->setLocalTld($tld);
        $config->save();
    }

    protected function getAiProvider(): AiProvider
    {
        return $this->getGlobalConfig()->getAiProvider();
    }

    protected function setAiProvider(AiProvider|string $provider): void
    {
        $config = $this->getGlobalConfig();
        $config->setAiProvider($provider);
        $config->save();
    }

    protected function getAiApiKey(AiProvider|string|null $provider = null): ?string
    {
        $config = $this->getGlobalConfig();
        $provider = $provider ?? $config->getAiProvider();

        $providerName = $provider instanceof AiProvider ? $provider->value : $provider;

        return $config->getAiApiKey($provider) ?? env(strtoupper($providerName).'_API_KEY');
    }

    protected function setAiApiKey(string $key, AiProvider|string|null $provider = null): void
    {
        $config = $this->getGlobalConfig();
        $provider = $provider ?? $config->getAiProvider();
        $config->setAiApiKey($provider, $key);
        $config->save();
    }

    protected function checkCaTrust(): bool
    {
        if ($this->isDarwin()) {
            return Process::run('security find-certificate -c "Server Side Up CA"')->output() !== '';
        }

        if ($this->isLinux()) {
            return file_exists('/usr/local/share/ca-certificates/larakube-local-ca.crt');
        }

        return false;
    }
}
