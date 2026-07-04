<?php

/**
 * Multiple web hostnames per environment (additionalWebHosts) — for a Laravel
 * app using subdomain route groups, or just a second domain, routed to the
 * SAME web pod. Confirmed gotcha while designing this: kustomize's
 * strategic-merge `patches:` REPLACES list fields wholesale (no merge key
 * defined for IngressTLS in the K8s API schema) — so the local overlay patch
 * MUST loop the same hosts as the base, or a multi-host local env gets its
 * tls.hosts silently truncated back to one by the patch. The invariant that
 * prevents this — both templates must render the identical host list, in the
 * identical order — is asserted directly below rather than via a real
 * `kubectl kustomize` build: `kubectl` isn't available in CI (ubuntu-latest
 * has no kubectl preinstalled) and segfaults on any invocation in the local
 * dev container, so a live-build test would only ever skip, everywhere.
 */

use App\Data\ConfigData;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\InteractsWithArchitecturalEngine;
use Symfony\Component\Yaml\Yaml;

function multiHostConfig(): ConfigData
{
    return ConfigData::from([
        'name' => 'multihost',
        'serverVariation' => 'fpm-nginx',
        'phpVersion' => '8.5',
        'database' => 'sqlite',
        'environments' => [
            'local' => [
                'additionalWebHosts' => ['admin.multihost.kube', 'mybrand.kube'],
            ],
            'production' => [
                'hosts' => ['web' => 'app.example.com'],
                'additionalWebHosts' => ['admin.example.com', 'mybrand.io'],
            ],
        ],
    ]);
}

/** Scaffold a real project tree in a temp dir; caller cleans it up. */
function scaffoldMultiHostProject(ConfigData $config): string
{
    $tempDir = sys_get_temp_dir().'/larakube-multihost-'.uniqid();
    mkdir($tempDir, 0755, true);
    $config->setPath($tempDir);
    $config->resolveDependencies();

    $scaffolder = new class
    {
        use GeneratesProjectInfrastructure, InteractsWithArchitecturalEngine;

        public function run(ConfigData $config): void
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

    $scaffolder->run($config);

    return $tempDir;
}

test('production overlay renders one Ingress rule per web host, sharing the same backend, and lists them all under tls.hosts', function () {
    $manifests = generateManifestsAsArray(multiHostConfig());
    $ingress = $manifests['overlays/production/ingress-patch.yaml'];

    $hosts = array_column($ingress['spec']['rules'], 'host');
    expect($hosts)->toBe(['app.example.com', 'admin.example.com', 'mybrand.io']);

    foreach ($ingress['spec']['rules'] as $rule) {
        expect($rule['http']['paths'][0]['backend']['service']['name'])
            ->toBe($ingress['metadata']['name']);
    }

    expect($ingress['spec']['tls'][0]['hosts'])->toBe(['app.example.com', 'admin.example.com', 'mybrand.io']);
});

test('a project with no additionalWebHosts renders exactly the old single-rule shape (no regression)', function () {
    $config = ConfigData::from([
        'name' => 'singlehost',
        'serverVariation' => 'fpm-nginx',
        'phpVersion' => '8.5',
        'database' => 'sqlite',
        'environments' => [
            'local' => [],
            'production' => ['hosts' => ['web' => 'app.example.com']],
        ],
    ]);

    $ingress = generateManifestsAsArray($config)['overlays/production/ingress-patch.yaml'];

    expect(array_column($ingress['spec']['rules'], 'host'))->toBe(['app.example.com'])
        ->and($ingress['spec']['tls'][0]['hosts'])->toBe(['app.example.com']);
});

test('base and local-overlay-patch templates agree on the exact host list and order', function () {
    // Pins the invariant a kustomize strategic-merge relies on: both
    // templates must loop the identical getWebHosts('local') list, in the
    // identical order. If the local overlay patch's tls.hosts loop is ever
    // reverted to a single literal host, this assertion catches the drift
    // directly — kustomize's real merge behavior (patches REPLACE list
    // fields wholesale, per the file-level docblock above) is what makes
    // that drift dangerous, but isn't itself exercised here since kubectl
    // isn't reliably available in CI or this dev container.
    $config = multiHostConfig();
    $tempDir = scaffoldMultiHostProject($config);

    try {
        // Both files are multi-document YAML (several resources combined per
        // overlay), so pull out just the Ingress doc from each.
        $findIngress = function (string $path) {
            $raw = file_get_contents($path);
            foreach (preg_split('/^---$/m', $raw) ?: [] as $doc) {
                $parsed = Yaml::parse($doc);
                if (($parsed['kind'] ?? null) === 'Ingress') {
                    return $parsed;
                }
            }

            return null;
        };

        $baseIngress = $findIngress("{$tempDir}/.infrastructure/k8s/base/laravel.yaml");
        $localPatchIngress = $findIngress("{$tempDir}/.infrastructure/k8s/overlays/local/patches.yaml");

        expect($baseIngress)->not->toBeNull()
            ->and($localPatchIngress)->not->toBeNull()
            ->and($baseIngress['spec']['tls'][0]['hosts'])->toBe($config->getWebHosts('local'))
            ->and($localPatchIngress['spec']['tls'][0]['hosts'])->toBe($baseIngress['spec']['tls'][0]['hosts']);
    } finally {
        exec('rm -rf '.escapeshellarg($tempDir));
    }
});
