<?php

use App\Data\ConfigData;
use App\Enums\AppFramework;
use App\Enums\PackageManager;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\InteractsWithDocker;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;
use Symfony\Component\Yaml\Yaml;

/**
 * `larakube up --preview` exists because a frontend-only project is the one
 * case where local and production share no workload: locally the framework's
 * dev server both compiles and serves, while production is a prebuilt bundle
 * behind Caddy. Everything in that serving layer — the SPA fallback, the cache
 * headers, compression, the security headers — therefore executes ONLY in
 * production, which is exactly how two real bugs shipped on 2026-09-03: an
 * immutable-cache matcher that never matched Vite's `index-D5pO33-4.js`
 * filenames, and a `header /index.html` rule that matched neither `/` nor a
 * deep link because Caddy evaluates matchers before try_files rewrites.
 * Neither was reproducible locally, because locally there was no Caddy.
 */
function upPreviewConfig(string $path, AppFramework $framework = AppFramework::VITE): ConfigData
{
    $config = new ConfigData(id: 'spa', name: 'spa', path: $path, framework: $framework);
    $config->setEnvironments(['local', 'production']);
    $config->setPackageManager(PackageManager::NPM);

    return $config;
}

function upPreviewHolder(): object
{
    return new class
    {
        use GeneratesProjectInfrastructure, InteractsWithDocker, LaraKubeOutput;

        public function generate(ConfigData $config): void
        {
            $this->generateK8sManifests($config);
            $this->generateDockerfiles($config);
        }

        public function build(ConfigData $config): bool
        {
            return $this->buildStaticPreviewImage($config);
        }
    };
}

test('--preview is refused on stacks that have no separate serving layer', function (AppFramework $framework): void {
    $refusal = upPreviewHolder()->previewModeRefusal($framework, 'local');

    expect($refusal)->not->toBeNull()
        ->and($refusal[0])->toContain('frontend-only stacks')
        // The message has to name the framework, because the whole point of
        // the flag's existence is that a Laravel dev should read one line and
        // know it isn't for them.
        ->and($refusal[1][0])->toContain($framework->getLabel());
})->with([
    'laravel' => [AppFramework::LARAVEL],
    'nextjs' => [AppFramework::NEXTJS],
    'wordpress' => [AppFramework::WORDPRESS],
]);

test('--preview is refused with no framework at all', function (): void {
    expect(upPreviewHolder()->previewModeRefusal(null, 'local'))->not->toBeNull();
});

test('--preview is accepted for every static stack, local only', function (AppFramework $framework): void {
    expect(upPreviewHolder()->previewModeRefusal($framework, 'local'))->toBeNull();

    // It rehearses production on the LOCAL cluster; pointed at a real
    // environment it would be a deploy wearing the wrong name.
    $refusal = upPreviewHolder()->previewModeRefusal($framework, 'production');
    expect($refusal)->not->toBeNull()
        ->and($refusal[1][0])->toContain('cloud:deploy production');
})->with([
    'vite' => [AppFramework::VITE],
    'astro' => [AppFramework::ASTRO],
    'docusaurus' => [AppFramework::DOCUSAURUS],
]);

test('the preview overlay runs the production workload on its own host', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $tempDir = $temporaryDirectory->path();
    $config = upPreviewConfig($tempDir);

    upPreviewHolder()->generate($config);

    $preview = "{$tempDir}/.infrastructure/k8s/overlays/local/preview";

    expect(file_exists("{$preview}/kustomization.yaml"))->toBeTrue()
        ->and(file_exists("{$preview}/caddy.yaml"))->toBeTrue();

    $documents = array_map(
        fn (string $doc) => Yaml::parse($doc),
        array_values(array_filter(
            array_map('trim', preg_split('/^---$/m', (string) file_get_contents("{$preview}/caddy.yaml"))),
            fn (string $doc) => $doc !== '',
        )),
    );

    $deployment = collect($documents)->firstWhere('kind', 'Deployment');
    $service = collect($documents)->firstWhere('kind', 'Service');
    $ingress = collect($documents)->firstWhere('kind', 'Ingress');

    // Distinct resource names from the dev server's, which is what lets both
    // run at once — the point of the whole design. Sharing them made
    // `--preview` evict the very thing you want to compare against.
    expect($deployment['metadata']['name'])->toBe('web-preview')
        ->and($service['metadata']['name'])->toBe('web-preview')
        ->and($ingress['metadata']['name'])->toBe('web-preview')
        ->and($deployment['spec']['selector']['matchLabels']['app'])->toBe('web-preview')
        ->and($service['spec']['selector']['app'])->toBe('web-preview')
        ->and($deployment['spec']['template']['spec']['containers'][0]['image'])->toBe('spa:preview')
        // Caddy serves the baked bundle: nothing is fetched at runtime.
        ->and($deployment['spec']['template']['spec']['containers'][0])->not->toHaveKey('volumeMounts');

    // Its own host, and NOT the dev server's.
    expect($ingress['spec']['rules'][0]['host'])->toBe('preview.spa.'.$config->getLocalTld())
        ->and($ingress['spec']['rules'][0]['host'])->not->toBe($config->getWebHost('local'))
        ->and($ingress['spec']['tls'][0]['hosts'])->toBe(['preview.spa.'.$config->getLocalTld()]);

    // The label a plain `larakube up` uses to spare this pod from its
    // scale-to-zero. Without it, `up` parks preview at zero replicas and
    // nothing in the local overlay ever restores it.
    expect($deployment['metadata']['labels']['larakube-preview'] ?? null)->toBe('true');

    // A .test host can never pass an ACME HTTP-01 challenge — asking for one
    // leaves Traefik serving its built-in dev cert instead of the LaraKube
    // Local CA leaf, which is the 526 failure mode one layer down.
    expect($ingress['metadata']['annotations'])
        ->not->toHaveKey('traefik.ingress.kubernetes.io/router.tls.certresolver')
        ->and($ingress['metadata']['annotations'])
        ->not->toHaveKey('external-dns.alpha.kubernetes.io/cloudflare-proxied');

    // The parent overlay is untouched — `larakube up` still gets the dev server,
    // and the two overlays never reference each other's resources.
    expect(file_get_contents("{$tempDir}/.infrastructure/k8s/overlays/local/kustomization.yaml"))
        ->toContain('dev-server.yaml')
        ->not->toContain('caddy.yaml');

    // The dev server keeps the plain `web` names, so neither apply touches the
    // other's Deployment, Service or Ingress.
    expect(file_get_contents("{$tempDir}/.infrastructure/k8s/overlays/local/dev-server.yaml"))
        ->not->toContain('web-preview');
});

test('the cloud overlay is unaffected by the preview parameters', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $tempDir = $temporaryDirectory->path();

    upPreviewHolder()->generate(upPreviewConfig($tempDir));

    $production = (string) file_get_contents("{$tempDir}/.infrastructure/k8s/overlays/production/caddy.yaml");

    // The same template renders both; production must keep the bare `web`
    // names its kustomization's image transform and every rollout command
    // already target, and must NOT carry the local-only preview label.
    expect($production)->toContain('image: spa:latest')
        ->and($production)->not->toContain('web-preview')
        ->and($production)->not->toContain('larakube-preview')
        // …and it still asks for a real certificate.
        ->and($production)->toContain('certresolver: letsencrypt');
});

test('the preview build uses the deploy Dockerfile with the ship-guard lifted', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $tempDir = $temporaryDirectory->path();
    $config = upPreviewConfig($tempDir);

    upPreviewHolder()->generate($config);
    file_put_contents("{$tempDir}/.env", "VITE_API_URL=https://data.spa.test\n");

    $commands = [];
    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        return Process::result();
    });

    expect(upPreviewHolder()->build($config))->toBeTrue();

    $build = collect($commands)->first(fn ($c) => str_contains((string) $c, 'buildx build'));

    expect($build)->not->toBeNull()
        ->and($build)->toContain('-f '.escapeshellarg("{$tempDir}/Dockerfile.static"))
        ->and($build)->toContain('-t '.escapeshellarg('spa:preview'))
        // The local .env, not .env.production: VITE_* are compiled into the
        // bundle, so a local preview has to talk to the local backend.
        ->and($build)->toContain('--secret id=dotenv,src='.escapeshellarg("{$tempDir}/.env"))
        // …and that same local .env is precisely what the Dockerfile's
        // ship-guard rejects, so the rehearsal has to lift it.
        ->and($build)->toContain('--build-arg STRICT_HOSTS=0');
});

test('the preview build fails loudly when Dockerfile.static is missing', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $config = upPreviewConfig($temporaryDirectory->path());

    Process::fake();

    expect(upPreviewHolder()->build($config))->toBeFalse();
    Process::assertNothingRan();
});

test('the ship-guard stays on for every build that is not a local rehearsal', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $tempDir = $temporaryDirectory->path();

    upPreviewHolder()->generate(upPreviewConfig($tempDir));

    // Defaulting the ARG to 1 is what keeps a plain `docker build` and every
    // real deploy from shipping a bundle that points at a developer's machine.
    expect(file_get_contents("{$tempDir}/Dockerfile.static"))
        ->toContain('ARG STRICT_HOSTS=1')
        ->toContain('if [ "$STRICT_HOSTS" = "1" ] && grep');
});
