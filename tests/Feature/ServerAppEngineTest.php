<?php

use App\Data\ConfigData;
use App\Enums\AppFramework;
use App\Traits\GeneratesProjectInfrastructure;
use Spatie\TemporaryDirectory\TemporaryDirectory;
use Symfony\Component\Yaml\Yaml;

/**
 * Every server-app framework goes through the shared engine instead of the PHP
 * templates, which fataled on their null server variation. Frameworks without
 * an image template yet still get parseable manifests and no Dockerfile.
 */
test('every server-app framework generates parseable cloud manifests without touching the PHP templates', function (AppFramework $framework): void {
    $directory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $dir = $directory->path();
    file_put_contents("{$dir}/package.json", '{"scripts":{"start:dev":"nest start --watch"}}');

    $config = ConfigData::from([
        'name' => 'demo',
        'path' => $dir,
        'framework' => $framework->value,
        'environments' => ['local' => [], 'production' => ['hosts' => ['web' => 'demo.example.com']]],
    ]);

    $generator = new class
    {
        use GeneratesProjectInfrastructure;

        public function run(ConfigData $config): void
        {
            $this->generateDockerfiles($config);
            $this->generateK8sManifests($config);
        }

        protected function laraKubeWarn(string $message): void {}

        protected function laraKubeInfo(string $message): void {}
    };

    $generator->run($config);

    $deploymentFile = "{$dir}/.infrastructure/k8s/overlays/production/deployment.yaml";
    $deployment = Yaml::parse((string) file_get_contents($deploymentFile));
    $container = $deployment['spec']['template']['spec']['containers'][0];

    expect(file_exists("{$dir}/Dockerfile.php"))->toBeFalse()
        ->and($deployment['metadata']['name'])->toBe("demo-{$framework->value}")
        ->and($container['ports'][0]['containerPort'])->toBe($framework->containerPort())
        ->and($container['readinessProbe']['httpGet']['path'])->toBe($framework->healthProbePath());

    $directory->delete();
})->with(fn () => array_values(array_filter(AppFramework::cases(), fn (AppFramework $f) => $f->isServerApp())));
