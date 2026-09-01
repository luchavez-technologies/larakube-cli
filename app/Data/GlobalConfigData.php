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
        /** Cloudflare named-tunnel token reused across share sessions (optional). */
        public ?string $shareToken = null,
        /**
         * Per-app named-tunnel URLs stored after the first `larakube share` with a token.
         * Shape: ['appname' => ['web' => 'https://…', 'hmr' => 'https://…', 'storage' => 'https://…']]
         *
         * @var array<string, array<string, string>>
         */
        public array $shareUrls = [],
        /** DigitalOcean API token, passed to OpenTofu as TF_VAR_do_token (never written into HCL). */
        public ?string $doToken = null,
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
        /**
         * Machine-wide environment name -> kube-context map.
         *
         * Cluster tools are cluster-scoped and routinely run with no project
         * in sight, so "which cluster is production?" needs an answer that
         * does not live in any one project's blueprint. Recorded here the
         * first time a command learns it, and read by resolveToolContext().
         *
         * @var array<string, string>
         */
        public array $environmentContexts = [],
        public ?string $cloudflareToken = null,
    ) {}

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): void
    {
        $this->email = $email;
    }

    /** Recorded kube-context for an environment name, or null. */
    public function getEnvironmentContext(string $environment): ?string
    {
        $context = $this->environmentContexts[$environment] ?? null;

        return is_string($context) && $context !== '' ? $context : null;
    }

    public function setEnvironmentContext(string $environment, string $context): self
    {
        $this->environmentContexts[$environment] = $context;

        return $this;
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

    public function getShareToken(): ?string
    {
        return $this->shareToken;
    }

    public function setShareToken(?string $token): void
    {
        $this->shareToken = $token;
    }

    /** Stored named-tunnel URLs for one app (returns empty array if not yet configured). */
    public function getShareUrls(string $appName): array
    {
        return $this->shareUrls[$appName] ?? [];
    }

    /** Persist named-tunnel URLs for one app (merges with any existing keys). */
    public function setShareUrls(string $appName, array $urls): void
    {
        $this->shareUrls[$appName] = array_filter(array_merge(
            $this->shareUrls[$appName] ?? [],
            $urls,
        ));
    }

    public function getDefaultCloudProvider(): ?string
    {
        return $this->defaultCloudProvider;
    }

    public function setDefaultCloudProvider(?string $provider): void
    {
        $this->defaultCloudProvider = $provider;
    }

    public function getDoToken(): ?string
    {
        return $this->doToken;
    }

    public function setDoToken(?string $token): void
    {
        $this->doToken = $token ? trim($token) : null;
    }

    public function getCloudflareToken(): ?string
    {
        return $this->cloudflareToken;
    }

    public function setCloudflareToken(?string $token): void
    {
        $this->cloudflareToken = $token ? trim($token) : null;
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

        $data = $this->toArray();
        // Empty associative maps must serialize as {} not [] in JSON.
        foreach (['shareUrls', 'stacks', 'tofuPassphrases'] as $mapKey) {
            if (empty($data[$mapKey])) {
                $data[$mapKey] = new stdClass;
            }
        }

        self::atomicWriteJson($path, $data, 0600);
    }
}
