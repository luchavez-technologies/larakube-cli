<?php

/**
 * Pure-logic tests for the registry-less remote deploy (SSH-sideload): the
 * command-builders, the rollout-triggering tag, and the .env → ConfigMap/Secret
 * split. The actual build/ssh/kubectl I/O belongs in a droplet smoke test.
 */

use App\Data\RegistryData;
use App\Enums\RegistryProvider;
use App\Traits\InteractsWithRemoteDeploy;

function remoteDeploy(): object
{
    return new class
    {
        use InteractsWithRemoteDeploy;

        // Pin to the `kubectl kustomize` fallback so the command-builder assertions are
        // deterministic regardless of whether a standalone kustomize is installed on the
        // test host. The standalone form is covered by InteractsWithKustomizeTest.
        protected function kustomizeBin(): ?string
        {
            return null;
        }
    };
}

test('the per-host context name matches what cloud:init creates', function (): void {
    expect(remoteDeploy()->remoteContextName('159.223.43.95'))->toBe('larakube-159.223.43.95');
});

test('the production build cross-compiles for the amd64 node and targets the deploy stage', function (): void {
    $cmd = remoteDeploy()->buildProductionImageCommand('app-one:abc123', '/proj/Dockerfile.php', '/proj');

    expect($cmd)
        ->toContain('docker buildx build')
        ->toContain('--platform linux/amd64')   // arm Mac -> amd64 droplet
        ->toContain('--target deploy')           // production stage, not development
        ->toContain('--load')                    // so `docker save` can stream it
        ->toContain("-t 'app-one:abc123'");
});

test('normalizeArch maps uname / kubectl / override tokens to a docker platform', function (): void {
    $r = remoteDeploy();

    // amd64 family
    expect($r->normalizeArch('x86_64'))->toBe('linux/amd64')   // uname -m on a droplet
        ->and($r->normalizeArch('amd64'))->toBe('linux/amd64')  // kubectl nodeInfo / override
        // arm64 family
        ->and($r->normalizeArch('aarch64'))->toBe('linux/arm64') // uname -m on a Pi
        ->and($r->normalizeArch('arm64'))->toBe('linux/arm64')   // kubectl nodeInfo / override
        // 32-bit arm
        ->and($r->normalizeArch('armv7l'))->toBe('linux/arm/v7')
        // unknown / empty -> null so callers fall back
        ->and($r->normalizeArch('s390x'))->toBeNull()
        ->and($r->normalizeArch(''))->toBeNull()
        ->and($r->normalizeArch(null))->toBeNull();
});

test('the production build honours the resolved platform (native arm64 for a Pi)', function (): void {
    $cmd = remoteDeploy()->buildProductionImageCommand('app-one:abc123', '/proj/Dockerfile.php', '/proj', 'linux/arm64');

    expect($cmd)
        ->toContain('--platform linux/arm64')
        ->not->toContain('linux/amd64');
});

test('the registry build honours the resolved platform and defaults to amd64', function (): void {
    $r = remoteDeploy();

    expect($r->buildAndPushImageCommand('ghcr.io/me/app:abc', '/proj/Dockerfile.php', '/proj', 'linux/arm64'))
        ->toContain('--platform linux/arm64')
        ->toContain('--push')
        ->and($r->buildAndPushImageCommand('ghcr.io/me/app:abc', '/proj/Dockerfile.php', '/proj'))
        ->toContain('--platform linux/amd64');   // unchanged default
});

test('a dotenv secret path is passed as a BuildKit --secret flag when provided', function (): void {
    $cmd = remoteDeploy()->buildProductionImageCommand('app:prod', '/proj/Dockerfile.php', '/proj', 'linux/amd64', '/tmp/lk_dotenv_build_xyz');

    expect($cmd)
        ->toContain("--secret id=dotenv,src='/tmp/lk_dotenv_build_xyz'")
        ->toContain('--target deploy')
        ->toContain('--load')
        ->not->toContain('--build-arg');
});

test('no --secret flag appears when no dotenv path is given', function (): void {
    $cmd = remoteDeploy()->buildProductionImageCommand('app:prod', '/proj/Dockerfile.php', '/proj');

    expect($cmd)->not->toContain('--secret');
});

test('the sideload streams the saved image into the remote k3s containerd', function (): void {
    $r = remoteDeploy();
    $ssh = $r->sshBaseCommand('larakube', '159.223.43.95', 22, '/home/me/.ssh/id_rsa');
    $cmd = $r->sideloadOverSshCommand('app-one:abc123', $ssh);

    expect($ssh)->toContain('ssh -o StrictHostKeyChecking=no')->toContain("'larakube@159.223.43.95'")
        ->and($cmd)->toContain("docker save 'app-one:abc123' | ssh")
        ->and($cmd)->toContain("'sudo k3s ctr images import -'");
});

test('apply rewrites the local :latest tag to the sideloaded tag, on the env context', function (): void {
    $cmd = remoteDeploy()->applyWithImageRewriteCommand(
        'larakube-159.223.43.95', '/proj/.infrastructure/k8s/overlays/production', 'app-one:latest', 'app-one:abc123',
    );

    expect($cmd)
        ->toContain('kubectl kustomize')                                       // build renders locally — no --context needed
        ->not->toContain("--context 'larakube-159.223.43.95' kustomize")       // context only on the apply
        ->toContain('sed')
        ->toContain('s|image: app-one:latest|image: app-one:abc123|g')
        ->toContain("kubectl --context 'larakube-159.223.43.95' apply -f -");
});

test('the scoped apply drives kubectl via the scoped kubeconfig, not a named context', function (): void {
    $cmd = remoteDeploy()->applyWithImageRewriteUsingKubeconfig(
        '/tmp/lk_kubeconfig_x', '/proj/.infrastructure/k8s/overlays/production', 'app-one:latest', 'app-one:abc123',
    );

    expect($cmd)
        ->toContain('kubectl kustomize')                                       // build renders locally — no kubeconfig needed
        ->not->toContain("KUBECONFIG='/tmp/lk_kubeconfig_x' kubectl kustomize") // kubeconfig only on the apply
        ->toContain('s|image: app-one:latest|image: app-one:abc123|g')
        ->toContain("KUBECONFIG='/tmp/lk_kubeconfig_x' kubectl apply -f -")
        ->not->toContain('--context')      // never falls back to the admin context
        ->toContain('awk');                // strips the cluster-scoped Namespace doc
});

test('the scoped apply strips the cluster-scoped Namespace doc (deployer cannot apply it)', function (): void {
    $cmd = remoteDeploy()->dropNamespaceDocCommand();

    expect($cmd)
        ->toContain('awk')
        ->toContain('kind:[ \t]+Namespace')   // the doc it drops
        ->toContain('drop=1');
});

test('the image tag uses the git sha when present, else a timestamped fallback', function (): void {
    $r = remoteDeploy();

    expect($r->formatImageTag('  abc1234  ', 1000))->toBe('abc1234-1000')   // sha prefix + unique timestamp
        ->and($r->formatImageTag(null, 1717286400))->toBe('build-1717286400')
        ->and($r->formatImageTag('', 1717286400))->toBe('build-1717286400');
});

test('the registry deploy can pin an immutable digest reference, not just a mutable tag', function (): void {
    $registry = new RegistryData(provider: RegistryProvider::GHCR, image: 'me/app');
    $digest = 'sha256:'.str_repeat('a', 64);

    expect($registry->getFullImageReference('abc1234'))->toBe('ghcr.io/me/app:abc1234')   // mutable tag (re-pushable)
        ->and($registry->getDigestReference($digest))->toBe("ghcr.io/me/app@{$digest}");   // immutable, content-addressed
});

test('env split routes secrets to the Secret and the rest to the ConfigMap', function (): void {
    $r = remoteDeploy();
    $lines = [
        'APP_URL=https://app.test',
        '# a comment',
        'DB_HOST=postgres.larakube-plex.svc.cluster.local',
        'DB_PASSWORD=s3cr3t',
        'APP_KEY=base64:xxx',
        'NO_VALUE_LINE',
    ];

    // knownSecrets is a LIST of keys (as array_keys(getAllSecretEnvironmentVariables) gives).
    ['public' => $public, 'secret' => $secret] = $r->splitEnvForK8s($lines, ['APP_URL']);

    // APP_URL is a known secret here (passed in knownSecrets), so it's a secret;
    // DB_HOST is public; PASSWORD/KEY route to secret by heuristic.
    expect($secret)->toContain('APP_URL=https://app.test')
        ->toContain('DB_PASSWORD=s3cr3t')
        ->toContain('APP_KEY=base64:xxx')
        ->and($public)->toContain('DB_HOST=postgres.larakube-plex.svc.cluster.local')
        ->and($public)->not->toContain('a comment')        // comments skipped
        ->and($public)->not->toContain('NO_VALUE_LINE');   // non KEY=VALUE skipped
});
