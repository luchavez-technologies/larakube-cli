<?php

namespace App\Services;

use Illuminate\Console\OutputStyle;

/**
 * Encapsulates CLI runtime state and transient in-memory parameters.
 *
 * Scoped as a container singleton within the Laravel application lifecycle.
 * In production CLI runs, this lives for the duration of the command invocation.
 * In unit and feature tests, Laravel automatically creates a fresh container and
 * clears resolved instances on every test, guaranteeing zero cross-test leakage.
 */
class RuntimeContext
{
    protected bool $headerRendered = false;

    protected bool $jsonMode = false;

    protected ?string $lastError = null;

    protected ?OutputStyle $stdout = null;

    /**
     * Sensitive values registered for redaction in CLI output (keyed by value).
     *
     * @var array<string, true>
     */
    protected array $registeredSecrets = [];

    /**
     * Arbitrary transient parameters keyed by string.
     *
     * @var array<string, string>
     */
    protected array $transients = [];

    protected ?string $transientDoToken = null;

    protected ?string $transientHetznerToken = null;

    protected ?string $transientCloudflareToken = null;

    protected ?string $transientGcpAccount = null;

    protected ?string $transientGcpProject = null;

    protected ?string $transientGcpCredentials = null;

    protected ?string $transientAwsProfile = null;

    protected ?string $transientAwsRegion = null;

    protected ?string $transientAwsAccessKeyId = null;

    protected ?string $transientAwsSecretAccessKey = null;

    public function isHeaderRendered(): bool
    {
        return $this->headerRendered;
    }

    public function setHeaderRendered(bool $rendered = true): self
    {
        $this->headerRendered = $rendered;

        return $this;
    }

    public function isJsonMode(): bool
    {
        return $this->jsonMode;
    }

    public function isTesting(): bool
    {
        return app()->runningUnitTests();
    }

    public function setJsonMode(bool $jsonMode = true): self
    {
        $this->jsonMode = $jsonMode;

        return $this;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    public function setLastError(?string $lastError): self
    {
        $this->lastError = $lastError;

        return $this;
    }

    public function stdout(): ?OutputStyle
    {
        return $this->stdout;
    }

    public function setStdout(?OutputStyle $stdout): self
    {
        $this->stdout = $stdout;

        return $this;
    }

    /**
     * @return array<string, true>
     */
    public function registeredSecrets(): array
    {
        return $this->registeredSecrets;
    }

    public function registerSecret(?string $secret): self
    {
        $value = trim((string) $secret);
        if (strlen($value) >= 8) {
            $this->registeredSecrets[$value] = true;
        }

        return $this;
    }

    public function transientDoToken(): ?string
    {
        return $this->transientDoToken;
    }

    public function setTransientDoToken(?string $token): self
    {
        $this->transientDoToken = $token !== null ? trim($token) : null;

        return $this;
    }

    public function transientHetznerToken(): ?string
    {
        return $this->transientHetznerToken;
    }

    public function setTransientHetznerToken(?string $token): self
    {
        $this->transientHetznerToken = $token !== null ? trim($token) : null;

        return $this;
    }

    public function transientCloudflareToken(): ?string
    {
        return $this->transientCloudflareToken;
    }

    public function setTransientCloudflareToken(?string $token): self
    {
        $this->transientCloudflareToken = $token !== null ? trim($token) : null;

        return $this;
    }

    public function transientGcpAccount(): ?string
    {
        return $this->transientGcpAccount;
    }

    public function setTransientGcpAccount(?string $account): self
    {
        $this->transientGcpAccount = $account !== null ? trim($account) : null;

        return $this;
    }

    public function transientGcpProject(): ?string
    {
        return $this->transientGcpProject;
    }

    public function setTransientGcpProject(?string $project): self
    {
        $this->transientGcpProject = $project !== null ? trim($project) : null;

        return $this;
    }

    public function transientGcpCredentials(): ?string
    {
        return $this->transientGcpCredentials;
    }

    public function setTransientGcpCredentials(?string $credentials): self
    {
        $this->transientGcpCredentials = $credentials !== null ? trim($credentials) : null;

        return $this;
    }

    public function transientAwsProfile(): ?string
    {
        return $this->transientAwsProfile;
    }

    public function setTransientAwsProfile(?string $profile): self
    {
        $this->transientAwsProfile = $profile !== null ? trim($profile) : null;

        return $this;
    }

    public function transientAwsRegion(): ?string
    {
        return $this->transientAwsRegion;
    }

    public function setTransientAwsRegion(?string $region): self
    {
        $this->transientAwsRegion = $region !== null ? trim($region) : null;

        return $this;
    }

    public function transientAwsAccessKeyId(): ?string
    {
        return $this->transientAwsAccessKeyId;
    }

    public function setTransientAwsAccessKeyId(?string $keyId): self
    {
        $this->transientAwsAccessKeyId = $keyId !== null ? trim($keyId) : null;

        return $this;
    }

    public function transientAwsSecretAccessKey(): ?string
    {
        return $this->transientAwsSecretAccessKey;
    }

    public function setTransientAwsSecretAccessKey(?string $secret): self
    {
        $this->transientAwsSecretAccessKey = $secret !== null ? trim($secret) : null;

        return $this;
    }

    public function getTransient(string $key, ?string $default = null): ?string
    {
        return $this->transients[$key] ?? $default;
    }

    public function setTransient(string $key, ?string $value): self
    {
        if ($value === null) {
            unset($this->transients[$key]);
        } else {
            $this->transients[$key] = $value;
        }

        return $this;
    }

    public function hasTransient(string $key): bool
    {
        return array_key_exists($key, $this->transients);
    }

    public function clearTransients(): self
    {
        $this->transients = [];
        $this->transientDoToken = null;
        $this->transientHetznerToken = null;
        $this->transientCloudflareToken = null;
        $this->transientGcpAccount = null;
        $this->transientGcpProject = null;
        $this->transientGcpCredentials = null;
        $this->transientAwsProfile = null;
        $this->transientAwsRegion = null;
        $this->transientAwsAccessKeyId = null;
        $this->transientAwsSecretAccessKey = null;

        return $this;
    }

    public function flush(): self
    {
        $this->headerRendered = false;
        $this->jsonMode = false;
        $this->lastError = null;
        $this->stdout = null;
        $this->registeredSecrets = [];
        $this->clearTransients();

        return $this;
    }

    public function __get(string $name): mixed
    {
        if (property_exists($this, $name)) {
            return $this->{$name};
        }

        return $this->getTransient($name);
    }

    public function __set(string $name, mixed $value): void
    {
        if (property_exists($this, $name)) {
            $this->{$name} = $value;

            return;
        }

        $this->setTransient($name, is_string($value) ? $value : null);
    }

    public function __isset(string $name): bool
    {
        if (property_exists($this, $name)) {
            return isset($this->{$name});
        }

        return $this->hasTransient($name);
    }
}
