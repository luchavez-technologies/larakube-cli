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
