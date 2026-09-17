<?php

use App\Data\ConfigData;
use App\Enums\AppFramework;
use App\Enums\PackageManager;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\LaraKubeOutput;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/**
 * The Caddyfile is the whole production serving layer of a static site, and
 * a dev server resolves pages and redirects itself, so none of this shows up
 * locally.
 */
function staticCaddyfileRender(AppFramework $framework, ?string $redirects = null): array
{
    $dir = TemporaryDirectory::make()->deleteWhenDestroyed();
    $path = $dir->path();
    file_put_contents("{$path}/package.json", json_encode(['scripts' => ['dev' => 'dev', 'build' => 'build']]));

    if ($redirects !== null) {
        mkdir("{$path}/{$framework->staticPublicDir()}", 0755, true);
        file_put_contents("{$path}/{$framework->staticPublicDir()}/_redirects", $redirects);
    }

    $holder = new class
    {
        use GeneratesProjectInfrastructure, LaraKubeOutput;

        /** @var list<string> */
        public array $warnings = [];

        public function generate(ConfigData $config): void
        {
            $this->generateStaticDockerfiles($config);
        }

        public function line($string, $style = null, $verbosity = null): void
        {
            $this->warnings[] = strip_tags((string) $string);
        }

        protected function laraKubeWarn(string $message): void
        {
            $this->warnings[] = $message;
        }
    };

    $holder->generate(ConfigData::forStaticSite($framework, 'site', $path, PackageManager::NPM));

    return [(string) file_get_contents("{$path}/Caddyfile"), $holder->warnings, $dir];
}

test('a multi-page site serves each page\'s own HTML and a real 404', function (AppFramework $framework): void {
    [$caddyfile] = staticCaddyfileRender($framework);

    expect($caddyfile)
        ->toContain('try_files {path} {path}/index.html {path}.html')
        ->toContain('handle_errors 404')
        ->toContain('rewrite * /404.html')
        // Falling back to the homepage is what served its HTML for every URL.
        ->not->toContain('/index.html'."\n");
})->with([
    'docusaurus' => [AppFramework::DOCUSAURUS],
    'astro' => [AppFramework::ASTRO],
]);

test('a Vite SPA keeps the app-shell fallback for client-side routes', function (): void {
    [$caddyfile] = staticCaddyfileRender(AppFramework::VITE);

    expect($caddyfile)
        ->toContain('try_files {path} {path}/index.html /index.html')
        ->not->toContain('handle_errors');
});

test('_redirects rules become Caddy redirects', function (): void {
    [$caddyfile, $warnings] = staticCaddyfileRender(AppFramework::DOCUSAURUS, <<<'TXT'
        # Merged pages
        /docs/old   /docs/new   301
        /docs/old/  /docs/new
        /moved      /elsewhere  302
        TXT);

    expect($caddyfile)
        ->toContain('redir /docs/old /docs/new 301')
        ->toContain('redir /docs/old/ /docs/new 301')
        ->toContain('redir /moved /elsewhere 302')
        ->and($warnings)->toBe([]);
});

test('_redirects rules with no plain-redirect equivalent are left out and reported', function (): void {
    [$caddyfile, $warnings] = staticCaddyfileRender(AppFramework::ASTRO, <<<'TXT'
        /blog/*        /news/:splat   301
        /api/*         https://api.example.com/:splat  200
        /ok            /fine          308
        TXT);

    expect($caddyfile)
        ->toContain('redir /ok /fine 308')
        ->not->toContain('/blog/')
        ->not->toContain('api.example.com')
        ->and(implode("\n", $warnings))
        ->toContain("public/_redirects has rules the Caddyfile can't serve")
        ->toContain('/blog/*')
        ->toContain('/api/*');
});
