<?php

namespace App\Data;

use App\Enums\AiProvider;
use App\Traits\InteractsWithJsonFile;
use Illuminate\Support\Carbon;
use Spatie\LaravelData\Data;
use stdClass;

class GlobalConfigData extends Data
{
    use InteractsWithJsonFile;

    const string CONFIG_FILE = 'config.json';

    const string DEFAULT_TLD = 'kube';

    /** Valid TLDs the user may choose. */
    const array ALLOWED_TLDS = ['kube', 'localhost', 'test', 'local', 'internal'];

    public function __construct(
        public ?string $email = null,
        public string $aiProvider = 'anthropic',
        public array $aiKeys = [],
        public ?string $lastStarPromptAt = null,
        public string $localTld = self::DEFAULT_TLD,
        /** DigitalOcean API token, passed to OpenTofu as TF_VAR_do_token (never written into HCL). */
        public ?string $doToken = null,
        /** Hetzner Cloud API token, passed to OpenTofu as TF_VAR_hcloud_token (never written into HCL). */
        public ?string $hetznerToken = null,
        /**
         * OpenTofu stack registry, keyed by stack name. Each value is a StackData
         * array. Global (not per-repo) so multiple projects can share one VPS/cluster.
         *
         * @var array<string, array<string, mixed>>
         */
        public array $stacks = [],
        /**
         * Per-stack OpenTofu state-encryption passphrases (PBKDF2), keyed by stack
         * name. Machine-local; supplied to Tofu via TF_ENCRYPTION at runtime so it
         * never enters committed HCL.
         *
         * @var array<string, string>
         */
        public array $tofuPassphrases = [],
        /**
         * Default cloud provider slug for cloud:create (e.g. 'do', 'aws').
         * Drives which Tofu templates get rendered.
         */
        public ?string $defaultCloudProvider = 'do',
        public ?string $latestVersion = null,
        public ?string $latestVersionCheckedAt = null,
        /** Cloudflare API token for ExternalDNS and tunnel configuration (optional). */
        public ?string $cloudflareToken = null,
        /** Google Cloud Platform account email (optional). */
        public ?string $gcpAccount = null,
        /** Google Cloud Platform project ID. */
        public ?string $gcpProjectId = null,
        /** Google Cloud Platform credentials path or JSON (optional if using ADC). */
        public ?string $gcpCredentials = null,
        /** AWS CLI profile name (optional). */
        public ?string $awsProfile = null,
        /** AWS default region (optional). */
        public ?string $awsRegion = null,
        /** AWS Access Key ID (optional). */
        public ?string $awsAccessKeyId = null,
        /** AWS Secret Access Key (optional). */
        public ?string $awsSecretAccessKey = null,
        /**
         * Named accounts registry for token-based cloud providers (do, hetzner).
         * Shape: ['do' => [['id' => 'do-1', 'name' => 'Agency Main', 'token' => '...', 'default' => true, 'createdAt' => '...']], ...]
         *
         * @var array<string, list<array{id: string, name: string, token: string, default: bool, createdAt: string}>>
         */
        public array $cloudAccounts = [],
        public array $shareDomains = [],
    ) {}

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): void
    {
        $this->email = $email;
    }

    public function getLocalTld(): string
    {
        return $this->localTld ?: self::DEFAULT_TLD;
    }

    public function setLocalTld(string $tld): void
    {
        $this->localTld = ltrim(strtolower(trim($tld)), '.');
    }

    public function getAiProvider(): AiProvider
    {
        return AiProvider::tryFrom($this->aiProvider) ?? AiProvider::ANTHROPIC;
    }

    public function setAiProvider(AiProvider|string $aiProvider): void
    {
        $this->aiProvider = $aiProvider instanceof AiProvider ? $aiProvider->value : $aiProvider;
    }

    public function getAiKeys(): array
    {
        return $this->aiKeys;
    }

    public function getAiApiKey(AiProvider|string $provider): ?string
    {
        $key = $provider instanceof AiProvider ? $provider->value : $provider;

        return $this->aiKeys[$key] ?? null;
    }

    public function setAiApiKey(AiProvider|string $provider, string $key): void
    {
        $providerKey = $provider instanceof AiProvider ? $provider->value : $provider;
        $this->aiKeys[$providerKey] = $key;
    }

    public function getLastStarPromptAt(): ?Carbon
    {
        return $this->lastStarPromptAt ? Carbon::parse($this->lastStarPromptAt) : null;
    }

    public function setLastStarPromptAt(Carbon $lastStarPromptAt): void
    {
        $this->lastStarPromptAt = $lastStarPromptAt->toString();
    }

    /** Stored named-tunnel URLs for one app (returns empty array if not yet configured). */
    /** @return array<string, mixed>|null */
    public function getShareDomain(string $appName): ?array
    {
        return $this->shareDomains[$appName] ?? null;
    }

    /** @param  array<string, mixed>|null  $share  null forgets it */
    public function setShareDomain(string $appName, ?array $share): void
    {
        if ($share === null) {
            unset($this->shareDomains[$appName]);

            return;
        }

        $this->shareDomains[$appName] = $share;
    }

    public function getDefaultCloudProvider(): ?string
    {
        return $this->defaultCloudProvider;
    }

    public function setDefaultCloudProvider(?string $provider): void
    {
        $this->defaultCloudProvider = $provider;
    }

    /**
     * @return list<array{id: string, name: string, token: string, default: bool, createdAt: string}>
     */
    public function getCloudAccounts(string $provider): array
    {
        $accounts = $this->cloudAccounts[$provider] ?? [];

        // Backwards compatibility migration for legacy doToken / hetznerToken
        if ($accounts === []) {
            $legacyToken = match ($provider) {
                'do' => $this->doToken,
                'hetzner' => $this->hetznerToken,
                default => null,
            };

            if ($legacyToken) {
                $accounts = [[
                    'id' => 'default',
                    'name' => 'Default Account',
                    'token' => $legacyToken,
                    'default' => true,
                    'createdAt' => Carbon::now()->toIso8601String(),
                ]];
            }
        }

        return $accounts;
    }

    public function addCloudAccount(string $provider, string $name, string $token, bool $asDefault = false): string
    {
        $accounts = $this->getCloudAccounts($provider);
        $id = 'acc_'.substr(md5(uniqid($name, true)), 0, 8);
        $token = trim($token);
        $name = trim($name);

        if ($asDefault || $accounts === []) {
            foreach ($accounts as &$account) {
                $account['default'] = false;
            }
            unset($account);
        }

        $accounts[] = [
            'id' => $id,
            'name' => $name,
            'token' => $token,
            'default' => $asDefault || count($accounts) === 0,
            'createdAt' => Carbon::now()->toIso8601String(),
        ];

        $this->cloudAccounts[$provider] = $accounts;

        if ($asDefault || count($accounts) === 1) {
            if ($provider === 'do') {
                $this->doToken = $token;
            } elseif ($provider === 'hetzner') {
                $this->hetznerToken = $token;
            }
        }

        return $id;
    }

    public function removeCloudAccount(string $provider, string $id): bool
    {
        $accounts = $this->getCloudAccounts($provider);
        $filtered = array_values(array_filter($accounts, fn (array $acc): bool => $acc['id'] !== $id));

        if (count($filtered) === count($accounts)) {
            return false;
        }

        if ($filtered !== [] && ! array_filter($filtered, fn (array $acc): bool => $acc['default'])) {
            $filtered[0]['default'] = true;
        }

        $this->cloudAccounts[$provider] = $filtered;

        $default = $this->getDefaultCloudAccount($provider);
        if ($provider === 'do') {
            $this->doToken = $default['token'] ?? null;
        } elseif ($provider === 'hetzner') {
            $this->hetznerToken = $default['token'] ?? null;
        }

        return true;
    }

    public function setDefaultCloudAccount(string $provider, string $id): bool
    {
        $accounts = $this->getCloudAccounts($provider);
        $found = false;

        foreach ($accounts as &$account) {
            if ($account['id'] === $id) {
                $account['default'] = true;
                $found = true;
                if ($provider === 'do') {
                    $this->doToken = $account['token'];
                } elseif ($provider === 'hetzner') {
                    $this->hetznerToken = $account['token'];
                }
            } else {
                $account['default'] = false;
            }
        }
        unset($account);

        if ($found) {
            $this->cloudAccounts[$provider] = $accounts;
        }

        return $found;
    }

    /**
     * @return array{id: string, name: string, token: string, default: bool, createdAt: string}|null
     */
    public function getDefaultCloudAccount(string $provider): ?array
    {
        $accounts = $this->getCloudAccounts($provider);

        foreach ($accounts as $account) {
            if ($account['default']) {
                return $account;
            }
        }

        return $accounts[0] ?? null;
    }

    public function getDoToken(): ?string
    {
        return $this->getDefaultCloudAccount('do')['token'] ?? $this->doToken;
    }

    public function setDoToken(?string $token): void
    {
        $this->doToken = $token ? trim($token) : null;
        if ($this->doToken) {
            $accounts = $this->getCloudAccounts('do');
            if ($accounts === []) {
                $this->addCloudAccount('do', 'Default Account', $this->doToken, asDefault: true);
            }
        }
    }

    public function getHetznerToken(): ?string
    {
        return $this->getDefaultCloudAccount('hetzner')['token'] ?? $this->hetznerToken;
    }

    public function setHetznerToken(?string $token): void
    {
        $this->hetznerToken = $token ? trim($token) : null;
        if ($this->hetznerToken) {
            $accounts = $this->getCloudAccounts('hetzner');
            if ($accounts === []) {
                $this->addCloudAccount('hetzner', 'Default Account', $this->hetznerToken, asDefault: true);
            }
        }
    }

    public function getCloudflareToken(): ?string
    {
        return $this->cloudflareToken;
    }

    public function setCloudflareToken(?string $token): void
    {
        $this->cloudflareToken = $token ? trim($token) : null;
    }

    public function getGcpAccount(): ?string
    {
        return $this->gcpAccount;
    }

    public function setGcpAccount(?string $account): void
    {
        $this->gcpAccount = $account ? trim($account) : null;
    }

    public function getGcpProjectId(): ?string
    {
        return $this->gcpProjectId;
    }

    public function setGcpProjectId(?string $projectId): void
    {
        $this->gcpProjectId = $projectId ? trim($projectId) : null;
    }

    public function getGcpCredentials(): ?string
    {
        return $this->gcpCredentials;
    }

    public function setGcpCredentials(?string $credentials): void
    {
        $this->gcpCredentials = $credentials ? trim($credentials) : null;
    }

    public function getAwsProfile(): ?string
    {
        return $this->awsProfile;
    }

    public function setAwsProfile(?string $profile): void
    {
        $this->awsProfile = $profile ? trim($profile) : null;
    }

    public function getAwsRegion(): ?string
    {
        return $this->awsRegion;
    }

    public function setAwsRegion(?string $region): void
    {
        $this->awsRegion = $region ? trim($region) : null;
    }

    public function getAwsAccessKeyId(): ?string
    {
        return $this->awsAccessKeyId;
    }

    public function setAwsAccessKeyId(?string $keyId): void
    {
        $this->awsAccessKeyId = $keyId ? trim($keyId) : null;
    }

    public function getAwsSecretAccessKey(): ?string
    {
        return $this->awsSecretAccessKey;
    }

    public function setAwsSecretAccessKey(?string $secret): void
    {
        $this->awsSecretAccessKey = $secret ? trim($secret) : null;
    }

    /**
     * All registered Tofu stacks, hydrated as StackData.
     *
     * @return array<string, StackData>
     */
    public function getStacks(): array
    {
        return array_map(fn (array $s) => StackData::from($s), $this->stacks);
    }

    public function findStack(string $name): ?StackData
    {
        return isset($this->stacks[$name]) ? StackData::from($this->stacks[$name]) : null;
    }

    public function putStack(StackData $stack): void
    {
        $this->stacks[$stack->name] = $stack->toArray();
    }

    public function removeStack(string $name): void
    {
        unset($this->stacks[$name], $this->tofuPassphrases[$name]);
    }

    /** Existing per-stack encryption passphrase, or null when none has been minted. */
    public function getTofuPassphrase(string $stack): ?string
    {
        return $this->tofuPassphrases[$stack] ?? null;
    }

    /**
     * The stack's encryption passphrase, minting a strong random one on first use.
     * PBKDF2 wants >=16 chars; we store hex so it's copy-safe. Caller must save().
     */
    public function ensureTofuPassphrase(string $stack): string
    {
        if (empty($this->tofuPassphrases[$stack])) {
            $this->tofuPassphrases[$stack] = bin2hex(random_bytes(24));
        }

        return $this->tofuPassphrases[$stack];
    }

    public static function load(): self
    {
        $path = home_path('.larakube/'.self::CONFIG_FILE);

        $data = self::readJsonFile($path);

        return $data === null ? new self : self::from($data);
    }

    public function save(): void
    {
        $dir = home_path('.larakube');
        $path = $dir.'/'.self::CONFIG_FILE;

        if (! is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        // The file is shared with LaraKube Desktop, which keeps its own settings in it (the switch for
        // experimental features, for one). Keys this class does not own are carried over untouched.
        $data = array_merge(array_diff_key(self::readJsonFile($path) ?? [], $this->toArray()), $this->toArray());
        // Empty associative maps must serialize as {} not [] in JSON.
        foreach (['shareDomains', 'stacks', 'tofuPassphrases'] as $mapKey) {
            if (empty($data[$mapKey])) {
                $data[$mapKey] = new stdClass;
            }
        }

        self::atomicWriteJson($path, $data, 0600);
    }
}
