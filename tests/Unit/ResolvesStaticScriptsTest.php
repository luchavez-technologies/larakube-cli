<?php

use App\Data\ConfigData;
use App\Enums\AppFramework;
use App\Enums\PackageManager;
use App\Traits\ResolvesStaticScripts;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/**
 * The manifest generator resolves a static site's dev and build commands from
 * the project's OWN package.json, using the framework enum only as an ordered
 * hint. This is the guard against the `doctor` failure: the enum said
 * `docusaurus => start`, but had it said `dev` — or had the scaffolder renamed
 * the script — the pod would CrashLoopBackOff on "Missing script". The file is
 * the source of truth; the enum only nominates candidates.
 */
function resolvesStaticScriptsHolder(): object
{
    return new class
    {
        use ResolvesStaticScripts;

        public function dev(ConfigData $config): string
        {
            return $this->resolveDevServerCommand($config);
        }

        public function build(ConfigData $config): string
        {
            return $this->resolveBuildCommand($config);
        }
    };
}

/**
 * Temp dirs are parked in a static so they are not garbage-collected (and
 * deleted) the moment this helper returns; pest tears the process down at the
 * end of the run, taking them with it.
 *
 * @var list<TemporaryDirectory>
 */
$GLOBALS['resolvesStaticScriptsDirs'] = [];

function resolvesStaticScriptsProject(AppFramework $framework, array $scripts): ConfigData
{
    $dir = TemporaryDirectory::make();
    $GLOBALS['resolvesStaticScriptsDirs'][] = $dir;
    file_put_contents($dir->path('package.json'), json_encode(['scripts' => $scripts]));

    $config = new ConfigData(id: 'demo', name: 'demo', path: $dir->path(), framework: $framework);
    $config->setPackageManager(PackageManager::NPM);

    return $config;
}

test('the dev command comes from package.json, plus the Pod-reachability flags', function (): void {
    // Docusaurus: `start`, no `dev`. The enum prefers `start`, the file has it,
    // and the K8s flags (which the file cannot know) are appended.
    $config = resolvesStaticScriptsProject(AppFramework::DOCUSAURUS, [
        'docusaurus' => 'docusaurus',
        'start' => 'docusaurus start',
        'build' => 'docusaurus build',
    ]);

    expect(resolvesStaticScriptsHolder()->dev($config))
        ->toBe('npm run start -- --host 0.0.0.0 --port 3000 --poll 300');
});

test('a project that renames its dev script is honoured, not overridden', function (): void {
    // Vite's hint is `dev`; if a project only declares `start`, resolution
    // still succeeds because `dev` is a candidate list, and here `start` would
    // NOT match — proving the file, not the enum, decides. A Docusaurus that
    // adds its own `dev` picks it up via the trailing fallback.
    $config = resolvesStaticScriptsProject(AppFramework::DOCUSAURUS, [
        'start' => 'docusaurus start',
        'dev' => 'docusaurus start --hot',
        'build' => 'docusaurus build',
    ]);

    // `start` still wins — it is first in the candidate list.
    expect(resolvesStaticScriptsHolder()->dev($config))
        ->toContain('npm run start');
});

test('the build command comes from the declared build script', function (): void {
    $config = resolvesStaticScriptsProject(AppFramework::VITE, [
        'dev' => 'vite',
        'build' => 'vite build',
    ]);

    expect(resolvesStaticScriptsHolder()->build($config))
        ->toBe('npm run build --');
});

test('a missing dev script fails loudly at generation, naming what it looked for', function (): void {
    // The whole point: a wrong/absent script surfaces HERE, as a clear error,
    // instead of as a CrashLoopBackOff in the cluster.
    $config = resolvesStaticScriptsProject(AppFramework::VITE, [
        'build' => 'vite build',
        // no dev/serve
    ]);

    expect(fn () => resolvesStaticScriptsHolder()->dev($config))
        ->toThrow(RuntimeException::class, 'No dev-server script found');
});

test('a package.json with no scripts block is reported as such', function (): void {
    $dir = TemporaryDirectory::make()->deleteWhenDestroyed();
    file_put_contents($dir->path('package.json'), json_encode(['name' => 'demo']));
    $config = new ConfigData(id: 'demo', name: 'demo', path: $dir->path(), framework: AppFramework::VITE);
    $config->setPackageManager(PackageManager::NPM);

    expect(fn () => resolvesStaticScriptsHolder()->build($config))
        ->toThrow(RuntimeException::class, 'no scripts block');
});

test('a malformed script value does not satisfy a candidate', function (): void {
    // A non-string value is not a command we can run, so it must not count as
    // the script being present.
    $config = resolvesStaticScriptsProject(AppFramework::VITE, [
        'build' => ['not', 'a', 'string'],
    ]);

    expect(fn () => resolvesStaticScriptsHolder()->build($config))
        ->toThrow(RuntimeException::class, 'No build script found');
});
