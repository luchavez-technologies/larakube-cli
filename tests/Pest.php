<?php

use App\Data\ConfigData;
/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\InteractsWithArchitecturalEngine;
use Laravel\Prompts\Prompt;
use Spatie\TemporaryDirectory\TemporaryDirectory;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

// Declares App\Traits's exec() override (namespaced functions need their own
// file — can't live inline here without wrapping this whole file's existing
// global-namespace code in a matching `namespace { ... }` block).
require_once __DIR__.'/Support/KubectlExecMock.php';

pest()->extend(TestCase::class)->in('Feature', 'Unit');

// Prompt::interactive(false) and Sleep::fake() both live in
// Tests\TestCase::setUp(), not here — a standalone top-level beforeEach()
// in this file did not reliably take effect for either (confirmed live:
// real interactive prompts still rendered and blocked on Enter in a real
// terminal, and polling loops still slept for real), while the identical
// calls inside setUp() do. See TestCase.php for the full note.

/**
 * Helper to generate manifests and return their content as a string for snapshotting.
 */
function generateManifests(ConfigData $config): string
{
    // Bypass all interactive prompts during tests
    Prompt::fallbackUsing(fn () => true);

    // A UNIQUE directory per call, not a shared fixed name — the old fixed
    // 'larakube-snapshot-stable-test' path was never actually required for
    // the snapshot normalization below (that already keys off the real
    // $tempDir value, whatever it is), but it did mean two of these running
    // concurrently under `pest --parallel` raced the same rm-rf/mkdir/write
    // cycle (confirmed live: "No such file or directory" on
    // kustomization.yaml, worker-order-dependent).
    // deleteWhenDestroyed(): a safety net for the NO_MANIFESTS_GENERATED
    // early return below, which (same as the pre-existing behavior) skips
    // the explicit delete() at the bottom of this function.
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $tempDir = $temporaryDirectory->path();

    $config->setPath($tempDir);

    // Ensure dependencies are resolved (e.g. Octane -> FrankenPHP)
    $config->resolveDependencies();

    // We need to mock some properties that the trait expects
    $test = new class
    {
        use GeneratesProjectInfrastructure, InteractsWithArchitecturalEngine;

        // Proxy to protected method
        public function runScaffolding(ConfigData $config)
        {
            $this->orchestrateProjectScaffolding($config, installFeatures: false, buildImage: false, dryRun: false);
        }

        // Mocking methods used in the trait
        public function line($string, $style = null, $verbosity = null) {}

        public function info($string, $verbosity = null) {}

        public function warn($string, $verbosity = null) {}

        public function error($string, $verbosity = null) {}

        public function newLine($count = 1) {}

        public function withSpin($text, $callback)
        {
            return $callback();
        }

        public function laraKubeInfo($text) {}
    };

    $test->runScaffolding($config);

    $k8sPath = $config->getK8sPath();
    $combined = '';

    if (! is_dir($k8sPath)) {
        return 'NO_MANIFESTS_GENERATED';
    }

    // Collect all generated files recursively
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($k8sPath));
    $fileList = [];
    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'yaml') {
            $fileList[] = $file->getPathname();
        }
    }
    sort($fileList);

    foreach ($fileList as $filePath) {
        $relative = str_replace($tempDir.'/.infrastructure/k8s/', '', $filePath);
        $content = file_get_contents($filePath);

        // NORMALIZE: Replace the dynamic temp dir path with a placeholder
        $content = str_replace($tempDir, '/STABLE_TEST_PATH', $content);

        // NORMALIZE: Replace random APP_KEY with a placeholder
        $content = preg_replace('/APP_KEY: "base64:.*?"/', 'APP_KEY: "PLACEHOLDER_KEY"', $content);
        $content = preg_replace('/APP_KEY: base64:.*? /', 'APP_KEY: PLACEHOLDER_KEY ', $content);

        $combined .= "--- FILE: {$relative} ---\n";
        $combined .= $content."\n\n";
    }

    $temporaryDirectory->delete();

    return $combined;
}

/**
 * Generate manifests and return them as an array of parsed YAML objects.
 */
function generateManifestsAsArray(ConfigData $config): array
{
    // Bypass all interactive prompts during tests
    Prompt::fallbackUsing(fn () => true);

    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $tempDir = $temporaryDirectory->path();

    $config->setPath($tempDir);
    $config->resolveDependencies();

    $test = new class
    {
        use GeneratesProjectInfrastructure, InteractsWithArchitecturalEngine;

        public function runScaffolding(ConfigData $config)
        {
            $this->orchestrateProjectScaffolding($config, installFeatures: false, buildImage: false, dryRun: false);
        }

        public function line($string, $style = null, $verbosity = null) {}

        public function info($string, $verbosity = null) {}

        public function warn($string, $verbosity = null) {}

        public function error($string, $verbosity = null) {}

        public function newLine($count = 1) {}

        public function withSpin($text, $callback)
        {
            return $callback();
        }

        public function laraKubeInfo($text) {}
    };

    $test->runScaffolding($config);

    $k8sPath = $config->getK8sPath();
    $manifests = [];

    if (is_dir($k8sPath)) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($k8sPath));
        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'yaml') {
                $relative = str_replace($tempDir.'/.infrastructure/k8s/', '', $file->getPathname());
                $content = file_get_contents($file->getPathname());

                if (str_contains($content, '---')) {
                    $docs = explode('---', $content);
                    $parsedDocs = [];
                    foreach ($docs as $doc) {
                        if (trim($doc)) {
                            try {
                                $parsedDocs[] = Yaml::parse($doc);
                            } catch (Exception) {
                                // Skip unparseable docs (e.g. comments only)
                            }
                        }
                    }
                    $manifests[$relative] = $parsedDocs;
                } else {
                    try {
                        $manifests[$relative] = Yaml::parse($content);
                    } catch (Exception) {
                        $manifests[$relative] = null;
                    }
                }
            }
        }
    }

    $temporaryDirectory->delete();

    return $manifests;
}

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something(): void
{
    // ..
}
