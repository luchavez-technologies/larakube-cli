<?php

namespace App\Commands\Templates;

use App\Facades\State;
use App\Http\Integrations\LaraKubeCloud\LaraKubeCloudConnector;
use App\Http\Integrations\LaraKubeCloud\Requests\AppReleases;
use App\Http\Integrations\LaraKubeCloud\Requests\PublishRelease;
use App\Support\Templates\CanonicalJson;
use App\Support\Templates\ImageDigestResolver;
use App\Support\Templates\ReleaseManifestBuilder;
use App\Support\Templates\ReleaseVerifier;
use App\Support\Templates\TemplateApp;
use App\Support\Templates\TrustedKeys;
use App\Traits\EmitsJsonOutput;
use App\Traits\FailsWithJson;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsCommandOptions;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;
use RuntimeException;
use Throwable;

class TemplatesPublishCommand extends Command
{
    use EmitsJsonOutput, FailsWithJson, LaraKubeOutput, ReadsCommandOptions;

    private const SEMVER_PATTERN = '/^\d+\.\d+\.\d+$/';

    protected $signature = 'templates:publish
        {apps* : App slugs, e.g. openbao external-dns}
        {--release-version= : Semver for every app in this run; default is the next patch after the app\'s newest release on LaraKube Cloud, or 1.0.0}
        {--security : Mark these releases as security fixes}
        {--dry-run : Build and sign, but don\'t upload}
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = 'Build, sign, and upload app template releases to LaraKube Cloud';

    public function handle(
        ReleaseManifestBuilder $builder,
        ImageDigestResolver $digests,
    ): int {
        if ($this->flag('json')) {
            $this->enableJsonMode();
        } else {
            State::setJsonMode(false);
            State::setStdout(null);
        }

        $dryRun = (bool) $this->option('dry-run');
        $security = (bool) $this->option('security');
        $explicitVersion = trim((string) ($this->option('release-version') ?? ''));

        if ($explicitVersion !== '' && preg_match(self::SEMVER_PATTERN, $explicitVersion) !== 1) {
            return $this->failed("Invalid release version '{$explicitVersion}'. Expected X.Y.Z.");
        }

        $signingKeyB64 = $this->readEnv('LARAKUBE_TEMPLATE_SIGNING_KEY');
        $keyId = $this->readEnv('LARAKUBE_TEMPLATE_SIGNING_KEY_ID');
        $uploadToken = $this->readEnv('LARAKUBE_RELEASE_UPLOAD_TOKEN');

        $missing = [];
        if ($signingKeyB64 === '') {
            $missing[] = 'LARAKUBE_TEMPLATE_SIGNING_KEY';
        }
        if ($keyId === '') {
            $missing[] = 'LARAKUBE_TEMPLATE_SIGNING_KEY_ID';
        }
        if (! $dryRun && $uploadToken === '') {
            $missing[] = 'LARAKUBE_RELEASE_UPLOAD_TOKEN';
        }

        if ($missing !== []) {
            return $this->failed('Missing required environment variable(s): '.implode(', ', $missing).'.');
        }

        $tools = [];
        foreach ($this->normalizeAppArguments((array) $this->argument('apps')) as $appSlug) {
            $tool = TemplateApp::find($appSlug);
            if ($tool === null) {
                return $this->failed("Unknown app '{$appSlug}'.");
            }
            $tools[] = $tool;
        }

        if ($tools === []) {
            return $this->failed('At least one app slug is required.');
        }

        $secretKey = base64_decode($signingKeyB64, true);
        if ($secretKey === false || strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            return $this->failed('LARAKUBE_TEMPLATE_SIGNING_KEY is not a valid base64-encoded Ed25519 secret key.');
        }

        try {
            $derivedPublicKey = sodium_crypto_sign_publickey_from_secretkey($secretKey);
            $derivedPublicKeyB64 = base64_encode($derivedPublicKey);

            $trustedKeys = TrustedKeys::all();
            $trustedPublicKeyB64 = $trustedKeys[$keyId] ?? null;

            if (! is_string($trustedPublicKeyB64) || ! hash_equals($trustedPublicKeyB64, $derivedPublicKeyB64)) {
                return $this->failed("Signing key '{$keyId}' does not match any trusted public key for this build channel.");
            }

            $cloud = new LaraKubeCloudConnector;
            $releases = [];

            foreach ($tools as $tool) {
                try {
                    $version = $explicitVersion !== ''
                        ? $explicitVersion
                        : $this->resolveNextPatchVersion($cloud, $tool);

                    $built = $builder->build($tool, $tool->defaultEngine(), $version, $security, $digests);
                    $manifest = $built['manifest'];
                    $files = $built['files'];

                    $canonical = CanonicalJson::encode($manifest);
                    $signatureB64 = base64_encode(sodium_crypto_sign_detached($canonical, $secretKey));

                    if (! ReleaseVerifier::verify($manifest, $signatureB64, $derivedPublicKeyB64)) {
                        return $this->failed("Signature verification against derived public key failed for '{$tool->slug}'.");
                    }

                    if ($dryRun) {
                        $releases[] = [
                            'app' => $tool->slug,
                            'engine' => $manifest['engine'],
                            'version' => $version,
                            'images' => count($manifest['images']),
                            'files' => count($files),
                            'status' => null,
                            'result' => 'dry-run',
                            'key_id' => $keyId,
                            'signature' => $signatureB64,
                            'manifest' => $manifest,
                        ];

                        continue;
                    }

                    $response = $cloud->send(new PublishRelease(
                        token: $uploadToken,
                        manifest: $manifest,
                        signature: $signatureB64,
                        keyId: $keyId,
                        files: $files,
                    ));

                    $status = $response->status();

                    if ($status === 201) {
                        $publishedApp = $response->json('app');
                        $publishedVersion = $response->json('version');

                        $releases[] = [
                            'app' => is_string($publishedApp) && $publishedApp !== '' ? $publishedApp : $tool->slug,
                            'engine' => $manifest['engine'],
                            'version' => is_string($publishedVersion) && $publishedVersion !== '' ? $publishedVersion : $version,
                            'images' => count($manifest['images']),
                            'files' => count($files),
                            'status' => 201,
                            'result' => 'published (201)',
                            'key_id' => $keyId,
                            'signature' => $signatureB64,
                            'manifest' => $manifest,
                        ];

                        continue;
                    }

                    if ($status === 409) {
                        $releases[] = [
                            'app' => $tool->slug,
                            'engine' => $manifest['engine'],
                            'version' => $version,
                            'images' => count($manifest['images']),
                            'files' => count($files),
                            'status' => 409,
                            'result' => 'version exists (409)',
                            'key_id' => $keyId,
                            'signature' => $signatureB64,
                            'manifest' => $manifest,
                        ];

                        continue;
                    }

                    $errorMessage = trim((string) ($response->json('message') ?? $response->json('error') ?? ''));
                    if ($errorMessage === '') {
                        $errorMessage = "LaraKube Cloud returned HTTP {$status} when uploading '{$tool->slug}'.";
                    }

                    return $this->failed($errorMessage);
                } catch (Throwable $e) {
                    return $this->failed($e->getMessage());
                }
            }
        } finally {
            sodium_memzero($secretKey);
        }

        if ($this->flag('json')) {
            $this->jsonOutput(['releases' => $releases]);

            return self::SUCCESS;
        }

        $this->renderHeader();

        table(
            headers: ['App', 'Version', 'Images', 'Files', 'Result'],
            rows: array_map(
                static fn (array $release): array => [
                    (string) $release['app'],
                    (string) $release['version'],
                    (string) $release['images'],
                    (string) $release['files'],
                    (string) $release['result'],
                ],
                $releases,
            ),
        );

        return self::SUCCESS;
    }

    private function resolveNextPatchVersion(LaraKubeCloudConnector $cloud, TemplateApp $tool): string
    {
        $response = $cloud->send(new AppReleases($tool->slug));

        if ($response->status() === 404) {
            return '1.0.0';
        }

        if (! $response->successful()) {
            $message = trim((string) ($response->json('message') ?? $response->json('error') ?? ''));

            throw new RuntimeException(
                $message !== ''
                    ? $message
                    : "LaraKube Cloud returned HTTP {$response->status()} when fetching releases for '{$tool->slug}'.",
            );
        }

        $rawVersions = $response->json('versions');
        if (! is_array($rawVersions) || $rawVersions === []) {
            return '1.0.0';
        }

        $semverVersions = [];
        foreach ($rawVersions as $candidate) {
            if (is_string($candidate) && preg_match(self::SEMVER_PATTERN, $candidate) === 1) {
                $semverVersions[] = $candidate;
            }
        }

        if ($semverVersions === []) {
            return '1.0.0';
        }

        usort($semverVersions, 'version_compare');
        $latest = $semverVersions[array_key_last($semverVersions)];
        [$major, $minor, $patch] = array_map('intval', explode('.', $latest, 3));

        return sprintf('%d.%d.%d', $major, $minor, $patch + 1);
    }

    /**
     * @param  array<array-key, mixed>  $rawArgs
     * @return list<string>
     */
    private function normalizeAppArguments(array $rawArgs): array
    {
        $slugs = [];

        foreach ($rawArgs as $arg) {
            $parts = preg_split('/\s+/', strtolower(trim((string) $arg))) ?: [];
            foreach ($parts as $part) {
                if ($part !== '') {
                    $slugs[] = $part;
                }
            }
        }

        return $slugs;
    }

    private function readEnv(string $name): string
    {
        $value = getenv($name);
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        $fallback = $_ENV[$name] ?? $_SERVER[$name] ?? null;

        return is_string($fallback) ? trim($fallback) : '';
    }
}
