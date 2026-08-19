<?php

use Symfony\Component\Yaml\Yaml;

/**
 * Public self-registration has no legitimate use on this cluster — every
 * real user already has a Zitadel identity, and an anonymous account is free
 * to push unbounded LFS blobs into the shared Commons storage/disk. These
 * two settings must always ship together: DISABLE_REGISTRATION blocks the
 * local /user/sign_up form, and ENABLE_AUTO_REGISTRATION keeps a teammate's
 * first Zitadel SSO login working without a manual account-creation step.
 */
test('forgejo manifest renders valid multi-document YAML with public registration disabled but OIDC auto-registration on', function (): void {
    $rendered = view('k8s.git.forgejo', [
        'host' => 'git.luchtech.dev',
        'plexNamespace' => 'larakube-plex',
        'redisIndex' => 3,
        's3Host' => 'files.luchtech.dev',
        's3AccessKey' => 'ak',
        's3SecretKey' => 'sk',
        'forgejoVersion' => '16.0.1',
        'runnerVersion' => '6.4.0',
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

        if (($parsed['kind'] ?? null) === 'Deployment' && ($parsed['metadata']['name'] ?? null) === 'forgejo') {
            $forgejoDeployment = $parsed;
        }
    }

    expect($forgejoDeployment)->not->toBeNull();

    $env = collect($forgejoDeployment['spec']['template']['spec']['containers'][0]['env'])
        ->mapWithKeys(fn (array $e) => [$e['name'] => $e['value'] ?? null]);

    expect($env->get('FORGEJO__service__DISABLE_REGISTRATION'))->toBe('true')
        ->and($env->get('FORGEJO__oauth2_client__ENABLE_AUTO_REGISTRATION'))->toBe('true');
});
