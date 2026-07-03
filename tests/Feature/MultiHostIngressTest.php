<?php

/**
 * Multiple web hostnames per environment (additionalWebHosts) — for a Laravel
 * app using subdomain route groups, or just a second domain, routed to the
 * SAME web pod. Confirmed gotcha while designing this: kustomize's
 * strategic-merge `patches:` REPLACES list fields wholesale (no merge key
 * defined for IngressTLS in the K8s API schema) — so the local overlay patch
 * MUST loop the same hosts as the base, or a multi-host local env gets its
 * tls.hosts silently truncated back to one by the patch. These tests run a
 * REAL `kubectl kustomize` build (not just render the raw template) to prove
 * that merge actually produces the full host list, not just assert the
 * template's own output.
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

/** Real `kubectl kustomize` build, parsed into a list of YAML documents. */
function kustomizeBuild(string $overlayPath): array
{
    $yaml = shell_exec('kubectl kustomize '.escapeshellarg($overlayPath).' 2>&1');
    expect($yaml)->not->toBeNull();

    $docs = array_map(
        fn (string $doc) => Yaml::parse($doc),
        preg_split('/^---$/m', $yaml) ?: [],
    );

    return array_values(array_filter($docs));
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

test('REAL kustomize build: the local overlay patch does not truncate tls.hosts back to one host', function () {
    // `kubectl` segfaults for ANY invocation in this dev container (confirmed:
    // even `kubectl version --client` crashes — an arm64/emulation issue, not
    // a kustomize-specific one). Skip rather than false-fail; the structural
    // test below covers the same regression without needing a real build.
    exec('kubectl version --client 2>&1', $out, $kubectlWorks);
    if ($kubectlWorks !== 0) {
        $this->markTestSkipped('kubectl is unusable in this environment (segfaults) — see structural test below instead');
    }

    $config = multiHostConfig();
    $expectedHosts = $config->getWebHosts('local');
    expect($expectedHosts)->toHaveCount(3); // primary + the 2 additionalWebHosts above

    $tempDir = scaffoldMultiHostProject($config);

    try {
        $docs = kustomizeBuild("{$tempDir}/.infrastructure/k8s/overlays/local");
        $ingress = collect($docs)->first(fn ($doc) => ($doc['kind'] ?? null) === 'Ingress');

        expect($ingress)->not->toBeNull('kustomize build produced no Ingress document');

        // The regression this test exists to catch: if the local overlay
        // patch's tls.hosts loop is ever reverted to a single literal host,
        // kustomize's strategic-merge patch REPLACES the base's list with
        // that single host — this assertion would drop back to count(1).
        expect($ingress['spec']['tls'][0]['hosts'])->toEqualCanonicalizing($expectedHosts);
    } finally {
        exec('rm -rf '.escapeshellarg($tempDir));
    }
});

test('structural fallback (no live kustomize build available): base and local-overlay-patch templates agree on the exact host list and order', function () {
    // Doesn't exercise the real strategic-merge (see the skipped test above),
    // but pins the invariant that merge relies on: both templates must loop
    // the identical getWebHosts('local') list, in the identical order, or a
    // merge — real or hypothetical — could disagree between them.
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
