<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\AiProvider;
use App\Enums\CliTool;
use App\State;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

trait InteractsWithGlobalConfig
{
    use InteractsWithOs, ResolvesContainerRuntime;

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
        $resolved = CliTool::GH->resolveBinary();
        if ($resolved !== null) {
            return $resolved;
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

        return $this->runContainerCommand("--rm {$interactiveFlag} {$mountString} -w /work alpine:latest sh -c 'apk add --no-cache github-cli >/dev/null && gh \"\$@\"' larakube-gh ");
    }

    protected function getTeaConfigPath(): string
    {
        return home_path('.larakube/tea-config');
    }

    /**
     * Gitea's official CLI, which also drives Forgejo. A host install wins;
     * otherwise the official image runs through the resolved container
     * runtime, so nothing needs installing. Arguments are appended by the
     * caller, e.g. `{$tea} logins list`. $envNames are forwarded into the
     * container by name only, so their values never appear in its arguments.
     *
     * @param  list<string>  $envNames
     */
    protected function getTeaCommand(bool $interactive = false, array $envNames = []): string
    {
        $resolved = CliTool::TEA->resolveBinary();
        if ($resolved !== null) {
            return $resolved;
        }

        $configPath = $this->getTeaConfigPath();
        if (! is_dir($configPath)) {
            @mkdir($configPath, 0700, true);
        }

        $interactiveFlag = $interactive ? '-it' : '-i';
        $envFlags = implode('', array_map(fn (string $name) => '-e '.escapeshellarg($name).' ', $envNames));

        // The image's entrypoint is `sh -c`, so the arguments reach tea through
        // "$@". Root inside the container is the host user under rootless
        // Podman, which keeps the mounted config writable.
        //
        // tea probes the working directory with `git rev-parse` even when
        // --repo is given, and aborts if git is missing — which it is in the
        // official image. No checkout is mounted, so a stub answering "not a
        // git repository" is the truthful reply, and tea then uses --repo.
        $script = 'printf \'#!/bin/sh\necho "fatal: not a git repository" >&2\nexit 128\n\' > /tmp/git'
            .' && chmod +x /tmp/git && PATH=/tmp:$PATH tea "$@"';

        return $this->runContainerCommand(
            "--rm {$interactiveFlag} {$envFlags}--user 0 -v ".escapeshellarg($configPath).':/app/.config/tea '
            .'docker.io/gitea/tea:0.16.0 '.escapeshellarg($script).' tea ',
        );
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

    protected function getGcpAccount(): ?string
    {
        if (State::$transientGcpAccount) {
            return State::$transientGcpAccount;
        }

        $envAccount = getenv('CLOUDSDK_CORE_ACCOUNT') ?: getenv('GCP_ACCOUNT');
        if ($envAccount) {
            return trim($envAccount);
        }

        $persisted = $this->getGlobalConfig()->getGcpAccount();
        if ($persisted) {
            return $persisted;
        }

        // Auto-detect from local gcloud CLI if available
        $gcloudAccount = trim(Process::run('gcloud config get-value account 2>/dev/null')->output());
        if ($gcloudAccount !== '' && $gcloudAccount !== '(unset)' && ! str_contains($gcloudAccount, 'ERROR:')) {
            return $gcloudAccount;
        }

        return null;
    }

    protected function setGcpAccount(?string $account): void
    {
        $config = $this->getGlobalConfig();
        $config->setGcpAccount($account);
        $config->save();
    }

    protected function getGcpProjectId(): ?string
    {
        if (State::$transientGcpProject) {
            return State::$transientGcpProject;
        }

        $envProject = getenv('GOOGLE_PROJECT') ?: (getenv('CLOUDSDK_CORE_PROJECT') ?: getenv('GCP_PROJECT'));
        if ($envProject) {
            return trim($envProject);
        }

        $persisted = $this->getGlobalConfig()->getGcpProjectId();
        if ($persisted) {
            return $persisted;
        }

        // Auto-detect from local gcloud CLI if available
        $gcloudProject = trim(Process::run('gcloud config get-value project 2>/dev/null')->output());
        if ($gcloudProject !== '' && $gcloudProject !== '(unset)' && ! str_contains($gcloudProject, 'ERROR:')) {
            return $gcloudProject;
        }

        return null;
    }

    protected function setGcpProjectId(?string $projectId): void
    {
        $config = $this->getGlobalConfig();
        $config->setGcpProjectId($projectId);
        $config->save();
    }

    protected function getGcpCredentials(): ?string
    {
        if (State::$transientGcpCredentials) {
            return State::$transientGcpCredentials;
        }

        $envCreds = getenv('GOOGLE_APPLICATION_CREDENTIALS') ?: getenv('GOOGLE_CREDENTIALS');
        if ($envCreds) {
            return trim($envCreds);
        }

        $persisted = $this->getGlobalConfig()->getGcpCredentials();
        if ($persisted) {
            return $persisted;
        }

        // Standard gcloud Application Default Credentials (ADC) location
        $adcPath = home_path('.config/gcloud/application_default_credentials.json');
        if (file_exists($adcPath)) {
            return $adcPath;
        }

        return null;
    }

    protected function setGcpCredentials(?string $credentials): void
    {
        $config = $this->getGlobalConfig();
        $config->setGcpCredentials($credentials);
        $config->save();
    }

    protected function getAwsProfile(): ?string
    {
        if (State::$transientAwsProfile) {
            return State::$transientAwsProfile;
        }

        $envProfile = getenv('AWS_PROFILE');
        if ($envProfile) {
            return trim($envProfile);
        }

        return $this->getGlobalConfig()->getAwsProfile();
    }

    protected function setAwsProfile(?string $profile): void
    {
        $config = $this->getGlobalConfig();
        $config->setAwsProfile($profile);
        $config->save();
    }

    protected function getAwsRegion(): ?string
    {
        if (State::$transientAwsRegion) {
            return State::$transientAwsRegion;
        }

        $envRegion = getenv('AWS_DEFAULT_REGION') ?: getenv('AWS_REGION');
        if ($envRegion) {
            return trim($envRegion);
        }

        return $this->getGlobalConfig()->getAwsRegion();
    }

    protected function setAwsRegion(?string $region): void
    {
        $config = $this->getGlobalConfig();
        $config->setAwsRegion($region);
        $config->save();
    }

    protected function getAwsAccessKeyId(): ?string
    {
        if (State::$transientAwsAccessKeyId) {
            return State::$transientAwsAccessKeyId;
        }

        $envKey = getenv('AWS_ACCESS_KEY_ID');
        if ($envKey) {
            return trim($envKey);
        }

        return $this->getGlobalConfig()->getAwsAccessKeyId();
    }

    protected function setAwsAccessKeyId(?string $keyId): void
    {
        $config = $this->getGlobalConfig();
        $config->setAwsAccessKeyId($keyId);
        $config->save();
    }

    protected function getAwsSecretAccessKey(): ?string
    {
        if (State::$transientAwsSecretAccessKey) {
            return State::$transientAwsSecretAccessKey;
        }

        $envSecret = getenv('AWS_SECRET_ACCESS_KEY');
        if ($envSecret) {
            return trim($envSecret);
        }

        return $this->getGlobalConfig()->getAwsSecretAccessKey();
    }

    protected function setAwsSecretAccessKey(?string $secret): void
    {
        $config = $this->getGlobalConfig();
        $config->setAwsSecretAccessKey($secret);
        $config->save();
    }

    protected function getCloudflareToken(): ?string
    {
        // Precedence: run-only --cloudflare-token (transient, never persisted)
        // → PROJECT-scoped token in the gitignored .larakube.local.json → the
        // operator's global token. The project token is what lets two clusters
        // on different domains each carry a zone-scoped token so their
        // ExternalDNS instances can't create/delete each other's records.
        return State::$transientCloudflareToken
            ?? $this->projectCloudflareToken()
            ?? $this->getGlobalConfig()->getCloudflareToken();
    }

    /** The Cloudflare token stored in this project's .larakube.local.json, if any. */
    protected function projectCloudflareToken(): ?string
    {
        if (! is_file(getcwd().'/'.ConfigData::CONFIG_FILE)) {
            return null;
        }

        try {
            return ConfigData::loadFromFile(getcwd())->getCloudflareToken();
        } catch (Throwable) {
            return null;
        }
    }

    protected function setCloudflareToken(?string $token): void
    {
        $config = $this->getGlobalConfig();
        $config->setCloudflareToken($token);
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
