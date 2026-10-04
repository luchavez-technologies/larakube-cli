<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Services\Devbox\DevBoxMarker;
use App\Services\Share\DomainShare;
use Illuminate\Support\Str;

use function Laravel\Prompts\password;

use Throwable;

/**
 * What `share:domain`, `share:domain-remove` and `share:domains` have in common: the Cloudflare API
 * token (from the environment, or typed in once and never kept), the project being shared, the name of
 * the machine it runs on, and one way to fail that a program can read.
 */
trait SharesUnderDomain
{
    use EmitsJsonOutput, InteractsWithEnvironments, InteractsWithGlobalConfig, InteractsWithProjectConfig, LaraKubeOutput, ReadsCommandOptions, RequiresFlagsWhenNonInteractive;

    protected function failed(string $message): int
    {
        $this->laraKubeError($message);

        if ($this->flag('json')) {
            $this->jsonOutput(['success' => false, 'error' => $message]);
        }

        return 1;
    }

    /**
     * The token is only ever read from the environment or typed in; it is not a flag because a flag is
     * visible in the process list, and it is not saved.
     */
    protected function domainToken(): ?string
    {
        $fromEnv = getenv('CLOUDFLARE_API_TOKEN');

        if (is_string($fromEnv) && trim($fromEnv) !== '') {
            return trim($fromEnv);
        }

        if ($this->cannotPrompt()) {
            return null;
        }

        $typed = trim((string) password(
            label: 'Cloudflare API token',
            hint: 'Needs Account → Cloudflare Tunnel → Edit, Zone → DNS → Edit and Zone → Zone → Read. It is used now and not stored.',
            required: true,
        ));

        return $typed !== '' ? $typed : null;
    }

    /** The domains the token can see, or null (after reporting) when it cannot list any. */
    protected function domainZones(DomainShare $share): ?array
    {
        try {
            $zones = $share->zones();
        } catch (Throwable $e) {
            $this->failed($e->getMessage());

            return null;
        }

        if ($zones === []) {
            $this->failed('This token cannot see any Cloudflare domain. Give it Zone → Zone → Read on the domain to use.');

            return null;
        }

        return $zones;
    }

    /** The name the machine goes by in public hostnames: the dev box's own name, else its host name. */
    protected function domainBoxName(): string
    {
        $flag = $this->flag('box');

        if (is_string($flag) && $flag !== '') {
            return $flag;
        }

        return DevBoxMarker::name() ?? Str::slug((string) gethostname()) ?: 'local';
    }

    /** @return array{0: ConfigData, 1: string, 2: string}|null project config, app name, namespace */
    protected function domainProject(): ?array
    {
        if (! $this->isLaraKubeProject()) {
            $this->failed('This folder is not a LaraKube project.');

            return null;
        }

        $projectPath = getcwd();
        $config = $this->getProjectConfig($projectPath);

        if (! $config) {
            $this->failed('The project blueprint could not be read.');

            return null;
        }

        $appName = $config->getName() ?? basename($projectPath);

        return [$config, $appName, $this->getNamespace('local', $appName)];
    }
}
