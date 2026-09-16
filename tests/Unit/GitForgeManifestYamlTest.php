<?php

use Symfony\Component\Yaml\Yaml;

/**
 * Public self-registration has no legitimate use on this cluster — every
 * real user already has a Zitadel identity, and an anonymous account is free
 * to push unbounded LFS blobs into the shared Commons storage/disk. These
 * settings must always ship together: DISABLE_REGISTRATION blocks the local
 * /user/sign_up form, ENABLE_AUTO_REGISTRATION keeps a teammate's first
 * Zitadel SSO login working without a manual account-creation step, and
 * ACCOUNT_LINKING=auto re-links an SSO identity whose `sub` changed (e.g.
 * after its Zitadel user was recreated) onto the existing local account by
 * email — instead of demanding a local password that SSO-only users never
 * had. USERNAME=preferred_username maps to the OIDC preferred_username
 * claim directly (Zitadel returns it under the `profile` scope). The
 * email type is unsafe: Forgejo's getUserName (auth.go:411) splits at "@"
 * for the username value, so admin@nexa-web.site → "admin" → reserved
 * name → 500 on first registration.
 */
test('forgejo manifest renders valid multi-document YAML with public registration disabled but OIDC auto-registration on', function (): void {
    $rendered = view('k8s.git.forgejo', [
        'host' => 'git.luchtech.dev',
        'instance' => 'git-luchtech-dev',
        'tenant' => 'forgejo_git_luchtech_dev',
        'buckets' => ['forgejo-storage-git-luchtech-dev', 'forgejo-packages-git-luchtech-dev', 'forgejo-lfs-git-luchtech-dev'],
        'plexNamespace' => 'larakube-plex',
        'redisIndex' => 3,
        's3Host' => 'files.luchtech.dev',
        's3AccessKey' => 'ak',
        's3SecretKey' => 'sk',
        'forgejoVersion' => '16.0.4',
        'runnerVersion' => '13.1.0',
        'appName' => null,
    ])->render();

    $documents = array_values(array_filter(
        array_map('trim', preg_split('/^---$/m', $rendered)),
        fn (string $doc) => $doc !== '',
    ));

    expect($documents)->not->toBeEmpty();

    $forgejoDeployment = null;
    foreach ($documents as $document) {
        try {
            $parsed = Yaml::parse($document);
        } catch (Throwable $e) {
            echo "\n--- FAILED DOCUMENT ---\n".$document."\n--- END DOCUMENT ---\n";
            throw $e;
        }
        expect($parsed)->toBeArray()->and($parsed['kind'] ?? null)->not->toBeNull();

        if (($parsed['kind'] ?? null) === 'Deployment' && ($parsed['metadata']['name'] ?? null) === 'git-forgejo-git-luchtech-dev') {
            $forgejoDeployment = $parsed;
        }
    }

    expect($forgejoDeployment)->not->toBeNull();

    $env = collect($forgejoDeployment['spec']['template']['spec']['containers'][0]['env'])
        ->mapWithKeys(fn (array $e) => [$e['name'] => $e['value'] ?? null]);

    expect($env->get('FORGEJO__service__DISABLE_REGISTRATION'))->toBe('true')
        ->and($env->get('FORGEJO__oauth2_client__ENABLE_AUTO_REGISTRATION'))->toBe('true')
        ->and($env->get('FORGEJO__oauth2_client__ACCOUNT_LINKING'))->toBe('auto')
        ->and($env->get('FORGEJO__oauth2_client__USERNAME'))->toBe('preferred_username');
});

test('runner config mounts the Podman socket into jobs and maps every label to the job image', function (): void {
    $rendered = view('k8s.git.forgejo', [
        'host' => 'git.example.com',
        'instance' => 'git-example-com',
        'tenant' => 'forgejo_git_example_com',
        'plexNamespace' => 'larakube-plex',
        'redisIndex' => 3,
        's3AccessKey' => 'ak',
        's3SecretKey' => 'sk',
        'volumeSize' => fn (string $name, string $default) => $default,
        'runnerSecret' => str_repeat('a', 40),
        'forgejoVersion' => '16.0.4',
        'runnerVersion' => '13.1.0',
        'podmanVersion' => 'v5.8.4',
        'jobImage' => 'node:24-trixie',
        'runnerLabels' => ['ubuntu-latest', 'docker'],
    ])->render();

    $documents = array_map(
        fn (string $doc) => Yaml::parse($doc),
        array_values(array_filter(array_map('trim', preg_split('/^---$/m', $rendered)), fn (string $doc) => $doc !== '')),
    );

    $configMap = collect($documents)->firstWhere('metadata.name', 'git-forgejo-runner-config-git-example-com');
    $runnerConfig = Yaml::parse($configMap['data']['config.yml']);
    $forgejo = collect($documents)->first(fn (array $doc) => $doc['kind'] === 'Deployment' && $doc['metadata']['name'] === 'git-forgejo-git-example-com');
    $runner = collect($documents)->firstWhere('metadata.name', 'git-forgejo-runner-git-example-com');
    $images = collect($runner['spec']['template']['spec']['containers'])->pluck('image');

    expect($runnerConfig['runner']['labels'])->toBe(['ubuntu-latest:docker://node:24-trixie', 'docker:docker://node:24-trixie'])
        ->and($runnerConfig['runner']['envs']['CONTAINER_HOST'])->toBe('unix:///var/run/docker.sock')
        ->and($runnerConfig['container']['docker_host'])->toBe('unix:///run/podman/podman.sock')
        ->and($runnerConfig['container']['network'])->toBe('host')
        ->and($forgejo['metadata']['labels']['larakube-tool'])->toBe('git')
        ->and($images->all())->toContain('quay.io/podman/stable:v5.8.4', 'code.forgejo.org/forgejo/runner:13.1.0');
});

test('changing the runner config changes the runner pod checksum, so the pod restarts to read it', function (): void {
    $checksum = function (string $jobImage): string {
        $rendered = view('k8s.git.forgejo', [
            'host' => 'git.example.com',
            'instance' => 'git-example-com',
            'tenant' => 'forgejo_git_example_com',
            'plexNamespace' => 'larakube-plex',
            'redisIndex' => 3,
            's3AccessKey' => 'ak',
            's3SecretKey' => 'sk',
            'volumeSize' => fn (string $name, string $default) => $default,
            'runnerSecret' => str_repeat('a', 40),
            'forgejoVersion' => '16.0.4',
            'runnerVersion' => '13.1.0',
            'podmanVersion' => 'v5.8.4',
            'jobImage' => $jobImage,
            'runnerLabels' => ['ubuntu-latest'],
        ])->render();

        $runner = collect(preg_split('/^---$/m', $rendered))
            ->map(fn (string $doc) => trim($doc))->filter()
            ->map(fn (string $doc) => Yaml::parse($doc))
            ->firstWhere('metadata.name', 'git-forgejo-runner-git-example-com');

        return $runner['spec']['template']['metadata']['annotations']['larakube.io/config-checksum'];
    };

    expect($checksum('node:24-trixie'))->toBe($checksum('node:24-trixie'))
        ->not->toBe($checksum('node:26-trixie'));
});
