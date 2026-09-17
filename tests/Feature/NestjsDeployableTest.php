<?php

use App\Data\ConfigData;
use App\Enums\AppFramework;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\PreparesNestjsProject;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;
use Symfony\Component\Yaml\Yaml;

/** Write what `nest new` produces: package.json scripts and an app.module.ts. */
function nestjsDeployableProject(string $dir, bool $esm = true): void
{
    @mkdir("{$dir}/src", 0755, true);
    file_put_contents("{$dir}/package.json", json_encode(['scripts' => [
        'build' => 'nest build',
        'start:dev' => 'nest start --watch',
        'start:prod' => 'node dist/main',
    ]]));
    $suffix = $esm ? '.js' : '';
    file_put_contents("{$dir}/src/app.module.ts", <<<TS
import { Module } from '@nestjs/common';
import { AppController } from './app.controller{$suffix}';
import { AppService } from './app.service{$suffix}';

@Module({
  imports: [],
  controllers: [AppController],
  providers: [AppService],
})
export class AppModule {}

TS);
}

function nestjsDeployableGenerator(): object
{
    return new class
    {
        use GeneratesProjectInfrastructure, PreparesNestjsProject;

        public function manifests(ConfigData $config): void
        {
            $this->generateK8sManifests($config);
        }

        public function dockerfiles(ConfigData $config): void
        {
            $this->generateDockerfiles($config);
        }

        public function health(string $dir): bool
        {
            return $this->addNestjsHealthController($dir);
        }

        protected function laraKubeInfo(string $message): void {}

        protected function laraKubeWarn(string $message): void {}
    };
}

/** @return array<string, array<string, mixed>> documents keyed by "Kind/name" */
function nestjsDeployableDocs(string $file): array
{
    $docs = [];
    foreach (preg_split('/^---$/m', (string) file_get_contents($file)) as $doc) {
        if (trim($doc) === '') {
            continue;
        }
        $parsed = Yaml::parse($doc);
        $docs[$parsed['kind'].'/'.$parsed['metadata']['name']] = $parsed;
    }

    return $docs;
}

test('nestjs:new scaffolds, adds a health endpoint, and writes an image and manifests instead of crashing', function (): void {
    $directory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $base = $directory->path();
    $previous = getcwd();

    Http::fake(['*' => Http::response([], 200)]);
    Process::fake([
        'command -v podman' => Process::result(exitCode: 1),
        'command -v docker' => Process::result(output: '/usr/bin/docker'),
        'which kubectl' => Process::result(output: '/usr/bin/kubectl'),
        'docker info' => Process::result(output: 'Server Version: 27.0.0'),
        '*@nestjs/cli@latest new shop*' => function () use ($base) {
            nestjsDeployableProject("{$base}/shop");

            return Process::result(output: 'created');
        },
        '*' => Process::result(),
    ]);

    try {
        chdir($base);

        $this->artisan('nestjs:new shop --fast --no-interaction')->assertExitCode(0);
    } finally {
        chdir($previous);
    }

    $project = "{$base}/shop";
    $k8s = "{$project}/.infrastructure/k8s";

    expect(file_get_contents("{$project}/src/app.module.ts"))
        ->toContain("import { HealthController } from './health.controller.js';")
        ->toContain('controllers: [AppController, HealthController]')
        ->and(file_exists("{$project}/src/health.controller.ts"))->toBeTrue()
        ->and(file_get_contents("{$project}/Dockerfile.nestjs"))->toContain('CMD ["node", "dist/main.js"]')
        ->and(file_get_contents("{$project}/.dockerignore"))->toContain('node_modules')
        ->and(file_get_contents("{$k8s}/overlays/local/dev-server.yaml"))->toContain('npm run start:dev');

    $preview = nestjsDeployableDocs("{$k8s}/overlays/local/preview/deployment.yaml")['Deployment/web-preview'];
    $container = $preview['spec']['template']['spec']['containers'][0];

    expect($container['ports'][0]['containerPort'])->toBe(3000)
        ->and($container['readinessProbe']['httpGet'])->toBe(['path' => '/healthz', 'port' => 3000])
        ->and($container['envFrom'][0]['secretRef']['name'])->toBe('shop-nestjs-secrets');

    $directory->delete();
});

test('a NestJS cloud overlay commits no credentials, reads dotenv:push env, and pulls with the registry secret', function (): void {
    $directory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $dir = $directory->path();
    nestjsDeployableProject($dir);
    file_put_contents("{$dir}/.env", "DATABASE_URL=postgresql://shop:hunter2@db:5432/shop\n");

    $config = ConfigData::from([
        'name' => 'shop',
        'path' => $dir,
        'framework' => 'nestjs',
        'environments' => ['local' => [], 'production' => [
            'hosts' => ['web' => 'shop.example.com'],
            'registry' => ['provider' => 'forgejo', 'image' => 'acme/shop', 'host' => 'git.example.com'],
        ]],
    ]);

    nestjsDeployableGenerator()->manifests($config);

    $overlay = "{$dir}/.infrastructure/k8s/overlays/production";
    $kustomization = Yaml::parse((string) file_get_contents("{$overlay}/kustomization.yaml"));
    $deployment = nestjsDeployableDocs("{$overlay}/deployment.yaml")['Deployment/shop-nestjs'];
    $pod = $deployment['spec']['template']['spec'];

    expect($kustomization['resources'])->toBe(['namespace.yaml', 'deployment.yaml', 'service.yaml', 'ingress.yaml'])
        ->and(file_exists("{$overlay}/secret.yaml"))->toBeFalse()
        ->and(implode("\n", array_map(fn ($f) => (string) file_get_contents($f), glob("{$overlay}/*.yaml"))))->not->toContain('hunter2')
        ->and($pod['imagePullSecrets'])->toBe([['name' => 'forgejo-login']])
        ->and($pod['containers'][0]['envFrom'][1]['secretRef']['name'])->toBe('laravel-secrets')
        ->and(nestjsDeployableDocs("{$overlay}/ingress.yaml")['Ingress/shop-nestjs']['metadata']['annotations'])
        ->toHaveKey('traefik.ingress.kubernetes.io/router.tls.certresolver');

    $directory->delete();
});

test('a migrate init container and Prisma steps appear only when the project has a Prisma schema', function (): void {
    $directory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $dir = $directory->path();
    nestjsDeployableProject($dir);
    $config = ConfigData::from(['name' => 'shop', 'path' => $dir, 'framework' => 'nestjs', 'environments' => ['local' => []]]);
    $generator = nestjsDeployableGenerator();

    $generator->dockerfiles($config);
    $generator->manifests($config);
    $withoutPrisma = [file_get_contents("{$dir}/Dockerfile.nestjs"), file_get_contents("{$dir}/.infrastructure/k8s/overlays/local/preview/deployment.yaml")];

    @mkdir("{$dir}/prisma");
    file_put_contents("{$dir}/prisma/schema.prisma", '');
    $generator->dockerfiles($config);
    $generator->manifests($config);

    expect($withoutPrisma[0])->not->toContain('prisma')
        ->and($withoutPrisma[1])->not->toContain('initContainers')
        ->and(file_get_contents("{$dir}/Dockerfile.nestjs"))->toContain('prisma@6 generate')->toContain('COPY --from=builder --chown=node:node /app/prisma ./prisma')
        ->and(file_get_contents("{$dir}/.infrastructure/k8s/overlays/local/preview/deployment.yaml"))->toContain('prisma@6 migrate deploy');

    $directory->delete();
});

test('the health endpoint follows a CommonJS module\'s import style, and an unrecognised module is left alone', function (): void {
    $directory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $dir = $directory->path();
    nestjsDeployableProject($dir, esm: false);

    expect(nestjsDeployableGenerator()->health($dir))->toBeTrue()
        ->and(file_get_contents("{$dir}/src/app.module.ts"))->toContain("import { HealthController } from './health.controller';");

    file_put_contents("{$dir}/src/app.module.ts", 'export class AppModule {}');
    unlink("{$dir}/src/health.controller.ts");

    expect(nestjsDeployableGenerator()->health($dir))->toBeFalse()
        ->and(file_exists("{$dir}/src/health.controller.ts"))->toBeFalse();

    $directory->delete();
});

test('connection URLs carrying credentials are pushed as secrets, whatever their key', function (): void {
    $runner = new class
    {
        use App\Traits\InteractsWithRemoteDeploy, App\Traits\LaraKubeOutput;
    };

    ['public' => $public, 'secret' => $secret] = $runner->splitEnvForK8s([
        'DATABASE_URL=postgresql://shop:hunter2@db:5432/shop',
        'REDIS_URL=redis://redis:6379',
        'APP_URL=https://shop.example.com',
    ], []);

    expect($secret)->toContain('DATABASE_URL')
        ->and($public)->toContain('REDIS_URL')->toContain('APP_URL')->not->toContain('DATABASE_URL');
});

test('the NestJS workflow builds Dockerfile.nestjs, checks the pushed runtime env, and rolls out its Deployment', function (): void {
    $config = new ConfigData(name: 'shop');
    $config->framework = AppFramework::NESTJS;
    $audit = ['skip' => true, 'strict' => false, 'gitleaks' => false, 'semgrep' => false, 'dependencyAudit' => false, 'trivy' => false, 'withTests' => false, 'failOn' => 'CRITICAL', 'auditLevel' => 'critical'];

    $workflow = view('k8s.cloud-pilot-deploy-server', [
        'config' => $config,
        'environment' => 'production',
        'branch' => 'main',
        'appName' => 'shop',
        'namespace' => 'shop-production',
        'podName' => 'shop-nestjs',
        'upperEnv' => 'PRODUCTION',
        'vpnHost' => null,
        'publicEnvScript' => '',
        'secrets' => ['k_env' => '${{ secrets.PRODUCTION_KUBECONFIG }}', 'k_base' => '', 'vpn_key' => ''],
        'gha' => [
            'forge' => 'forgejo', 'actor' => '', 'token' => '', 'registry_provider' => 'forgejo',
            'registry_host' => 'git.example.com', 'image_name' => 'acme/shop', 'k_data' => '${{ env.K_DATA }}',
            'registry_user' => '${{ secrets.PRODUCTION_REGISTRY_USERNAME }}', 'registry_password' => '${{ secrets.PRODUCTION_REGISTRY_PASSWORD }}',
            'push_ref_output' => '${{ steps.push.outputs.ref }}', 'image_ref' => '${{ needs.build.outputs.image_ref }}',
        ],
        'audit' => $audit,
    ])->render();

    expect($workflow)
        ->toContain('--file Dockerfile.nestjs')
        ->toContain('kubectl get secret laravel-secrets -n shop-production')
        ->toContain('kubectl rollout status deployment/shop-nestjs -n shop-production')
        ->and(Yaml::parse($workflow)['jobs'])->toHaveKeys(['build', 'deploy']);
});
