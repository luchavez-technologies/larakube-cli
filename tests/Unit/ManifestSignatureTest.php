<?php

use App\Data\ConfigData;
use App\Traits\GeneratesProjectInfrastructure;
use Spatie\TemporaryDirectory\TemporaryDirectory;

function manifestSigner(): object
{
    return new class
    {
        use GeneratesProjectInfrastructure;
    };
}

function sigTestConfig(string $dir): ConfigData
{
    mkdir($dir.'/.infrastructure/k8s/base', 0755, true);
    $config = new ConfigData(name: 'sig-test');
    $config->setPath($dir);

    return $config;
}

test('a tracked manifest is flagged hand-edited only once it diverges from the recorded hash', function (): void {
    $s = manifestSigner();
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $dir = $temporaryDirectory->path();
    $config = sigTestConfig($dir);

    $rel = 'base/deployment.yaml';
    $abs = $config->getK8sPath().'/'.$rel;
    $content = "kind: Deployment\nmetadata:\n  name: web";

    // Record the hash LaraKube "wrote".
    file_put_contents($abs, $content);
    $s->saveManifestSigs($config, [$rel => hash('sha256', $content)]);
    expect($s->manifestHandEdited($config, $abs, $rel))->toBeFalse();

    // Hand-edit the file → detected.
    file_put_contents($abs, str_replace('name: web', 'name: HACKED', $content));
    expect($s->manifestHandEdited($config, $abs, $rel))->toBeTrue();

    // A file with no recorded hash (pre-feature / external) is never flagged.
    expect($s->manifestHandEdited($config, $abs, 'base/untracked.yaml'))->toBeFalse();

    $temporaryDirectory->delete();
});

test('the signature sidecar round-trips and stays sorted', function (): void {
    $s = manifestSigner();
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $dir = $temporaryDirectory->path();
    $config = sigTestConfig($dir);

    $s->saveManifestSigs($config, ['b.yaml' => 'h2', 'a.yaml' => 'h1']);

    expect($s->loadManifestSigs($config))->toBe(['a.yaml' => 'h1', 'b.yaml' => 'h2']);

    $temporaryDirectory->delete();
});
