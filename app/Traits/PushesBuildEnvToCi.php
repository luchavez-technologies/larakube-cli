<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Enums\AppFramework;
use App\Enums\CliTool;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/**
 * Syncs build-time environment variables ({ENV}_BUILD_ENV) to the project's
 * configured CI forge (Forgejo via `tea`, GitHub via `gh`, or GitLab via
 * `glab`). Shared between `ConfiguresCloudEnvironment` and `DotenvPushCommand`.
 */
trait PushesBuildEnvToCi
{
    /**
     * Detect which CI forge this environment targets. Checks in priority order:
     * 1. Explicit `--platform` flag
     * 2. Persisted `environments.{env}.ciPlatform` in `.larakube.json`
     * 3. Hostname of the selected git remote (`gitlab`, `forgejo`/`git.*`, `github`)
     * 4. Environment `registry.provider` (`forgejo` or `gitlab`)
     * 5. Default fallback (`github`)
     */
    protected function detectCiPlatform(?ConfigData $config = null, ?string $environment = null): string
    {
        $platform = method_exists($this, 'flag') ? $this->flag('platform') : null;
        if (is_string($platform) && $platform !== '') {
            return $platform;
        }

        if ($config !== null && $environment !== null) {
            $savedPlatform = $config->getEnvironment($environment)?->ciPlatform;
            if (is_string($savedPlatform) && in_array($savedPlatform, ['github', 'forgejo', 'gitlab'], true)) {
                return $savedPlatform;
            }
        }

        $remote = $this->resolveEnvironmentRemoteUrl($config, $environment);
        if ($remote !== '') {
            $parsed = $this->parseGitRemote($remote);
            if ($parsed !== null) {
                if (($inferred = $this->inferPlatformFromHost($parsed['host'])) !== null) {
                    return $inferred;
                }

                // Custom self-hosted Forgejo domains (e.g. code.acme.dev) that
                // don't match standard host heuristics are still detected if
                // `tea` already holds a login for that host.
                if (CliTool::TEA->isAvailable() && $this->resolveTeaLoginName($this->getTeaCommand(), $parsed['host']) !== null) {
                    return 'forgejo';
                }
            }

            if (str_contains($remote, 'gitlab.com')) {
                return 'gitlab';
            }

            if (str_contains($remote, 'forgejo') || str_contains($remote, 'git.')) {
                return 'forgejo';
            }
        }

        if ($config !== null && $environment !== null) {
            $registryProvider = $config->getRegistry($environment)?->provider?->value;
            if ($registryProvider === 'forgejo' || $registryProvider === 'gitlab') {
                return $registryProvider;
            }
        }

        return 'github';
    }

    protected function inferPlatformFromHost(string $host): ?string
    {
        $host = strtolower(trim($host));

        if ($host === '') {
            return null;
        }

        if (str_contains($host, 'gitlab')) {
            return 'gitlab';
        }

        if (str_contains($host, 'forgejo')
            || str_contains($host, 'gitea')
            || str_contains($host, 'codeberg')
            || str_starts_with($host, 'git.')
            || str_contains($host, '.git.')) {
            return 'forgejo';
        }

        if (str_contains($host, 'github')) {
            return 'github';
        }

        return null;
    }

    /**
     * Resolve the remote URL for a given environment, honouring `environments.{env}.gitRemote`
     * when configured, while keeping 0-arg `gitRemoteUrl()` test overrides working cleanly.
     */
    protected function resolveEnvironmentRemoteUrl(?ConfigData $config = null, ?string $environment = null): string
    {
        $remoteName = ($config !== null && $environment !== null)
            ? ($config->getEnvironment($environment)?->gitRemote ?? 'origin')
            : 'origin';

        if ($remoteName !== '' && $remoteName !== 'origin') {
            $custom = trim(Process::run('git remote get-url '.escapeshellarg($remoteName))->output());
            if ($custom !== '') {
                return $custom;
            }
        }

        return $this->gitRemoteUrl();
    }

    /**
     * The project's `origin` remote URL, or '' if there's none (or no git
     * repo at all). Split out from detectCiPlatform() so tests can simulate
     * a remote by overriding this one method.
     */
    protected function gitRemoteUrl(): string
    {
        return trim(Process::run('git remote get-url origin')->output());
    }

    /**
     * Host and owner/repo from a git remote, across all standard URL shapes:
     * scp-like SSH (`git@host:slug`), SSH config aliases (`github:slug`),
     * `ssh://host:port/slug`, and HTTPS.
     *
     * @return array{host: string, slug: string}|null
     */
    protected function parseGitRemote(string $remote): ?array
    {
        $remote = trim($remote);

        if (preg_match('#^[a-z][a-z0-9+.-]*://(?:[^@/]+@)?([^/:]+)(?::\d+)?/(.+?)(?:\.git)?/?$#i', $remote, $m)
            || preg_match('#^(?:[^@]+@)?([^:/]+):(.+?)(?:\.git)?/?$#', $remote, $m)) {
            return ['host' => strtolower($m[1]), 'slug' => ltrim($m[2], '/')];
        }

        return null;
    }

    /** Whether tea holds a login for this forge host. */
    protected function teaHasLogin(string $tea, string $host, ?string $preferredLogin = null): bool
    {
        return $this->resolveTeaLoginName($tea, $host, $preferredLogin) !== null;
    }

    /**
     * Resolve the exact `tea` login `name` matching a Forgejo host (handles
     * custom login aliases like `--name work` and per-env `teaLogin` overrides).
     */
    protected function resolveTeaLoginName(string $tea, string $host, ?string $preferredLogin = null): ?string
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return null;
        }

        $logins = json_decode(Process::run(rtrim($tea).' logins list --output json')->output(), true);
        if (! is_array($logins)) {
            return null;
        }

        $matches = [];
        foreach ($logins as $login) {
            if (! is_array($login)) {
                continue;
            }

            $url = (string) ($login['url'] ?? $login['URL'] ?? '');
            $name = trim((string) ($login['name'] ?? $login['Name'] ?? ''));
            $urlHost = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));

            if ($name === '' && $urlHost === '') {
                continue;
            }

            if (strtolower($name) === $host || $urlHost === $host) {
                $matches[] = [
                    'name' => $name !== '' ? $name : $host,
                    'default' => (bool) ($login['default'] ?? $login['Default'] ?? false),
                ];
            }
        }

        if ($matches === []) {
            return null;
        }

        $preferred = trim((string) $preferredLogin);
        if ($preferred !== '') {
            foreach ($matches as $match) {
                if (strcasecmp($match['name'], $preferred) === 0) {
                    return $match['name'];
                }
            }
        }

        foreach ($matches as $match) {
            if (strtolower($match['name']) === $host) {
                return $match['name'];
            }
        }

        foreach ($matches as $match) {
            if ($match['default']) {
                return $match['name'];
            }
        }

        return $matches[0]['name'];
    }

    protected function resolveGlabCommand(): ?string
    {
        $candidates = array_filter([
            trim(Process::run('command -v glab')->output()),
            '/usr/local/bin/glab',
            '/opt/homebrew/bin/glab',
            '/home/linuxbrew/.linuxbrew/bin/glab',
        ]);

        foreach ($candidates as $path) {
            if ($path !== '' && @is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Store one Actions secret on GitHub through `gh`, never exposing its value
     * in process arguments. Returns success/error instead of terminating.
     *
     * @return array{ok: bool, error: ?string}
     */
    protected function trySetGithubSecret(string $gh, string $name, string $value, string $repoFlag): array
    {
        $temporaryDirectory = (new TemporaryDirectory)->permission(0700)->deleteWhenDestroyed()->create();
        $tmpFile = $temporaryDirectory->path().'/gh-secret';
        file_put_contents($tmpFile, $value);

        $command = 'cat '.escapeshellarg($tmpFile)." | {$gh} secret set ".escapeshellarg($name)." {$repoFlag}";
        $result = Process::run($command);
        $temporaryDirectory->delete();

        if (! $result->successful()) {
            $detail = trim($result->output().$result->errorOutput());

            return [
                'ok' => false,
                'error' => $detail !== '' ? $detail : "Failed to set GitHub secret: {$name}",
            ];
        }

        return ['ok' => true, 'error' => null];
    }

    protected function setGithubSecret(string $gh, string $name, string $value, string $repoFlag): void
    {
        $result = $this->trySetGithubSecret($gh, $name, $value, $repoFlag);

        if (! $result['ok']) {
            $this->laraKubeError("Failed to set GitHub secret: {$name}");
            foreach (explode("\n", (string) $result['error']) as $line) {
                $this->line("  <fg=red>{$line}</>");
            }
            exit(1);
        }

        $this->info("  ✅ Secret '{$name}' uploaded successfully.");
    }

    /**
     * Store one Actions secret on a Forgejo repository through tea, passing the
     * value on stdin from a 0700 temp directory. Never terminates the process.
     *
     * @return array{ok: bool, error: ?string}
     */
    protected function trySetTeaSecret(string $tea, string $name, string $value, string $repositorySlug, string $login): array
    {
        $temporaryDirectory = (new TemporaryDirectory)->permission(0700)->deleteWhenDestroyed()->create();
        $tmpFile = $temporaryDirectory->path().'/tea-secret';
        file_put_contents($tmpFile, $value);

        $command = 'cat '.escapeshellarg($tmpFile).' | '.rtrim($tea).' actions secrets create '.escapeshellarg($name)
            .' --stdin --repo '.escapeshellarg($repositorySlug).' --login '.escapeshellarg($login);
        $result = Process::run($command);
        $temporaryDirectory->delete();

        if (! $result->successful()) {
            $detail = trim($result->output().$result->errorOutput());

            return [
                'ok' => false,
                'error' => $detail !== '' ? $detail : "Failed to set Forgejo secret: {$name}",
            ];
        }

        return ['ok' => true, 'error' => null];
    }

    /**
     * Store one Actions secret on a Forgejo repository through tea. The value
     * travels on stdin from a 0600 temp file, never in the command line.
     */
    protected function setTeaSecret(string $tea, string $name, string $value, string $repositorySlug, string $login): void
    {
        $result = $this->trySetTeaSecret($tea, $name, $value, $repositorySlug, $login);

        if (! $result['ok']) {
            $this->laraKubeError("Failed to set Forgejo secret: {$name}");
            foreach (explode("\n", (string) $result['error']) as $line) {
                $this->line("  <fg=red>{$line}</>");
            }
            exit(1);
        }

        $this->info("  ✅ Secret '{$name}' uploaded successfully.");
    }

    /**
     * Extract build-time key/value pairs from `.env.{environment}` on disk.
     * Static sites include all keys (no runtime cluster Secret exists); hybrid
     * and server apps include only framework browser-bundle prefixes (VITE_*,
     * PUBLIC_*, NEXT_PUBLIC_*) so runtime credentials never enter CI.
     *
     * @return array<string, string>
     */
    protected function buildEnvPairs(ConfigData $config, string $environment, ?string $projectPath = null): array
    {
        $basePath = rtrim($projectPath ?: ($config->getPath() ?: (string) getcwd()), '/');
        $envPath = $basePath.'/'.($environment === 'local' ? '.env' : ".env.{$environment}");

        if (! is_file($envPath)) {
            return [];
        }

        $framework = $config->framework ?? AppFramework::LARAVEL;
        $isStatic = $framework->isStaticSpa();
        $prefixes = $framework->publicEnvPrefixes();

        if (! $isStatic && $prefixes === []) {
            return [];
        }

        $pairs = [];
        foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (! preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $matches)) {
                continue;
            }

            $key = $matches[1];
            if (! $isStatic) {
                $matched = false;
                foreach ($prefixes as $prefix) {
                    if (str_starts_with($key, $prefix)) {
                        $matched = true;

                        break;
                    }
                }
                if (! $matched) {
                    continue;
                }
            }

            $value = trim($matches[2]);
            if (strlen($value) >= 2 && in_array($value[0], ['"', "'"], true) && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            }

            $pairs[$key] = $value;
        }

        return $pairs;
    }

    /**
     * Render build-time env pairs as a `.env`-compatible multiline string for
     * storing in `{ENV}_BUILD_ENV`.
     */
    protected function buildEnvPayload(ConfigData $config, string $environment, ?string $projectPath = null): string
    {
        return $this->formatBuildEnvPayload($this->buildEnvPairs($config, $environment, $projectPath));
    }

    /**
     * @param  array<string, string>  $pairs
     */
    protected function formatBuildEnvPayload(array $pairs): string
    {
        if ($pairs === []) {
            return '';
        }

        $lines = [];
        foreach ($pairs as $key => $value) {
            $formatted = $value === ''
                ? ''
                : (preg_match('/[\s#"\'=\\\\]/', $value) === 1
                    ? '"'.str_replace(['\\', '"'], ['\\\\', '\"'], $value).'"'
                    : $value);
            $lines[] = "{$key}={$formatted}";
        }

        return implode("\n", $lines);
    }

    protected function hasConfiguredCiWorkflow(string $projectPath, string $environment): bool
    {
        $basePath = rtrim($projectPath, '/');

        return is_file("{$basePath}/.github/workflows/larakube-deploy-{$environment}.yml")
            || is_file("{$basePath}/.gitlab-ci.yml");
    }

    /**
     * Push `.env.{environment}` build-time variables to the detected CI platform
     * as `{ENV}_BUILD_ENV`.
     *
     * @return array{pushed: bool, platform: string, secretName: string, count: int, keys: list<string>, error: ?string}
     */
    protected function pushBuildEnvToCi(ConfigData $config, string $environment, ?string $projectPath = null): array
    {
        $projectPath ??= $config->getPath() ?: (string) getcwd();
        $upperEnv = strtoupper($environment);
        $secretName = "{$upperEnv}_BUILD_ENV";
        $platform = $this->detectCiPlatform($config, $environment);
        $pairs = $this->buildEnvPairs($config, $environment, $projectPath);
        $payload = $this->formatBuildEnvPayload($pairs);
        $keys = array_keys($pairs);

        if ($pairs === []) {
            return [
                'pushed' => false,
                'platform' => $platform,
                'secretName' => $secretName,
                'count' => 0,
                'keys' => [],
                'error' => "No build-time variables found in '.env.{$environment}'.",
            ];
        }

        $remote = $this->resolveEnvironmentRemoteUrl($config, $environment);
        if ($remote === '') {
            return [
                'pushed' => false,
                'platform' => $platform,
                'secretName' => $secretName,
                'count' => 0,
                'keys' => [],
                'error' => "No git remote found — add an 'origin' remote before pushing to CI.",
            ];
        }

        $repository = $this->parseGitRemote($remote);

        if ($platform === 'forgejo') {
            if ($repository === null) {
                return [
                    'pushed' => false,
                    'platform' => $platform,
                    'secretName' => $secretName,
                    'count' => 0,
                    'keys' => [],
                    'error' => "Could not parse a Forgejo repository from remote URL: {$remote}",
                ];
            }

            if (! CliTool::TEA->isAvailable()) {
                return [
                    'pushed' => false,
                    'platform' => $platform,
                    'secretName' => $secretName,
                    'count' => 0,
                    'keys' => [],
                    'error' => 'Tea CLI (tea) is required to upload Forgejo secrets (larakube setup --tools=tea).',
                ];
            }

            $tea = $this->getTeaCommand();
            $preferredLogin = $config->getEnvironment($environment)?->teaLogin;
            $teaLogin = $this->resolveTeaLoginName($tea, $repository['host'], $preferredLogin);
            if ($teaLogin === null) {
                return [
                    'pushed' => false,
                    'platform' => $platform,
                    'secretName' => $secretName,
                    'count' => 0,
                    'keys' => [],
                    'error' => "tea is not logged in to {$repository['host']}. Run 'larakube git:login {$environment}' first.",
                ];
            }

            $result = $this->trySetTeaSecret($tea, $secretName, $payload, $repository['slug'], $teaLogin);
            if (! $result['ok']) {
                return [
                    'pushed' => false,
                    'platform' => $platform,
                    'secretName' => $secretName,
                    'count' => 0,
                    'keys' => [],
                    'error' => $result['error'],
                ];
            }

            return [
                'pushed' => true,
                'platform' => $platform,
                'secretName' => $secretName,
                'count' => count($keys),
                'keys' => $keys,
                'error' => null,
            ];
        }

        if ($platform === 'gitlab') {
            $glab = $this->resolveGlabCommand();
            if ($glab === null) {
                return [
                    'pushed' => false,
                    'platform' => $platform,
                    'secretName' => $secretName,
                    'count' => 0,
                    'keys' => [],
                    'error' => '`glab` CLI not found — install GitLab CLI to push CI variables.',
                ];
            }

            $result = Process::run("{$glab} variable set ".escapeshellarg($secretName).' '.escapeshellarg($payload));
            if (! $result->successful()) {
                $detail = trim($result->output().$result->errorOutput());

                return [
                    'pushed' => false,
                    'platform' => $platform,
                    'secretName' => $secretName,
                    'count' => 0,
                    'keys' => [],
                    'error' => $detail !== '' ? $detail : "Failed to set GitLab variable: {$secretName}",
                ];
            }

            return [
                'pushed' => true,
                'platform' => $platform,
                'secretName' => $secretName,
                'count' => count($keys),
                'keys' => $keys,
                'error' => null,
            ];
        }

        if (! CliTool::GH->isAvailable()) {
            return [
                'pushed' => false,
                'platform' => $platform,
                'secretName' => $secretName,
                'count' => 0,
                'keys' => [],
                'error' => 'GitHub CLI (gh) is required to upload GitHub secrets (larakube setup --tools=gh).',
            ];
        }

        $repoFlag = ($repository !== null && str_contains($repository['host'], 'github'))
            ? '-R '.$repository['slug']
            : '';
        $gh = $this->getGhCommand();
        $result = $this->trySetGithubSecret($gh, $secretName, $payload, $repoFlag);

        if (! $result['ok']) {
            return [
                'pushed' => false,
                'platform' => $platform,
                'secretName' => $secretName,
                'count' => 0,
                'keys' => [],
                'error' => $result['error'],
            ];
        }

        return [
            'pushed' => true,
            'platform' => $platform,
            'secretName' => $secretName,
            'count' => count($keys),
            'keys' => $keys,
            'error' => null,
        ];
    }
}
