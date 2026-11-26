<?php

use App\Http\Integrations\Registry\Requests\HeadManifestRequest;
use App\Http\Integrations\Registry\Requests\RegistryTokenRequest;
use App\Support\Templates\ImageDigestResolver;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;

/**
 * @param  callable(ImageDigestResolver): void  $callback
 */
function templateImageDigestResolverWithMock(callable $callback): void
{
    try {
        $callback(new ImageDigestResolver);
    } finally {
        MockClient::destroyGlobal();
    }
}

test('parse normalizes docker.io single-name, multi-segment, and explicit allowed registries', function (): void {
    $resolver = new ImageDigestResolver;

    expect($resolver->parse('redis'))->toBe([
        'registry' => 'docker.io',
        'host' => 'registry-1.docker.io',
        'repo' => 'library/redis',
        'tag' => 'latest',
    ])
        ->and($resolver->parse('redis:7.4-alpine'))->toBe([
            'registry' => 'docker.io',
            'host' => 'registry-1.docker.io',
            'repo' => 'library/redis',
            'tag' => '7.4-alpine',
        ])
        ->and($resolver->parse('openbao/openbao:2.6.1'))->toBe([
            'registry' => 'docker.io',
            'host' => 'registry-1.docker.io',
            'repo' => 'openbao/openbao',
            'tag' => '2.6.1',
        ])
        ->and($resolver->parse('ghcr.io/stakater/reloader:v1.0.69'))->toBe([
            'registry' => 'ghcr.io',
            'host' => 'ghcr.io',
            'repo' => 'stakater/reloader',
            'tag' => 'v1.0.69',
        ])
        ->and($resolver->parse('oci.external-secrets.io/external-secrets/external-secrets:v2.11.0'))->toBe([
            'registry' => 'oci.external-secrets.io',
            'host' => 'oci.external-secrets.io',
            'repo' => 'external-secrets/external-secrets',
            'tag' => 'v2.11.0',
        ])
        ->and($resolver->parse('registry.k8s.io/external-dns/external-dns:v0.24.0'))->toBe([
            'registry' => 'registry.k8s.io',
            'host' => 'registry.k8s.io',
            'repo' => 'external-dns/external-dns',
            'tag' => 'v0.24.0',
        ])
        ->and($resolver->parse('codeberg.org/forgejo/forgejo:16.0.4'))->toBe([
            'registry' => 'codeberg.org',
            'host' => 'codeberg.org',
            'repo' => 'forgejo/forgejo',
            'tag' => '16.0.4',
        ])
        ->and($resolver->parse('code.forgejo.org/forgejo/runner:13.1.0'))->toBe([
            'registry' => 'code.forgejo.org',
            'host' => 'code.forgejo.org',
            'repo' => 'forgejo/runner',
            'tag' => '13.1.0',
        ])
        ->and($resolver->parse('oci.element.io/element-admin:0.1.12'))->toBe([
            'registry' => 'oci.element.io',
            'host' => 'oci.element.io',
            'repo' => 'element-admin',
            'tag' => '0.1.12',
        ]);
});

test('parse rejects digest references, invalid repo or tag characters, and unknown registries', function (): void {
    $resolver = new ImageDigestResolver;

    expect(fn () => $resolver->parse('openbao/openbao:2.6.1@sha256:'.str_repeat('a', 64)))
        ->toThrow(RuntimeException::class, '@')
        ->and(fn () => $resolver->parse('evil.example.com/org/app:1.0.0'))->toThrow(RuntimeException::class, 'evil.example.com')
        ->and(fn () => $resolver->parse('localhost:5000/org/app:1.0.0'))->toThrow(RuntimeException::class, 'localhost:5000')
        ->and(fn () => $resolver->parse('ghcr.io/UpperCase/Repo:1.0.0'))->toThrow(RuntimeException::class, 'Invalid image repository')
        ->and(fn () => $resolver->parse('openbao/openbao:'))->toThrow(RuntimeException::class, 'Invalid image tag');
});

test('resolve completes 401 Bearer token flow and returns verified sha256 digest', function (): void {
    templateImageDigestResolverWithMock(function (ImageDigestResolver $resolver): void {
        $expectedDigest = 'sha256:5b2486ab0fb90bbc788cc345b0a08616dfb375873ee8be5df3a2fd4d378a67e0';
        $headAttempts = 0;

        $mock = MockClient::global([
            HeadManifestRequest::class => function (PendingRequest $pending) use (&$headAttempts, $expectedDigest): MockResponse {
                $headAttempts++;
                /** @var HeadManifestRequest $request */
                $request = $pending->getRequest();

                expect($pending->headers()->get('Accept'))->toBe(HeadManifestRequest::ACCEPT_HEADER);

                if ($headAttempts === 1) {
                    expect($request->token)->toBeNull();

                    return MockResponse::make('', 401, [
                        'WWW-Authenticate' => 'Bearer realm="https://auth.docker.io/token",service="registry.docker.io",scope="repository:openbao/openbao:pull"',
                    ]);
                }

                expect($request->token)->toBe('jwt-token-123')
                    ->and($pending->headers()->get('Authorization'))->toBe('Bearer jwt-token-123');

                return MockResponse::make('', 200, [
                    'Docker-Content-Digest' => $expectedDigest,
                ]);
            },
            RegistryTokenRequest::class => function (PendingRequest $pending): MockResponse {
                /** @var RegistryTokenRequest $request */
                $request = $pending->getRequest();

                expect($pending->getUrl())->toBe('https://auth.docker.io/token')
                    ->and($request->service)->toBe('registry.docker.io')
                    ->and($request->scope)->toBe('repository:openbao/openbao:pull');

                return MockResponse::make(['access_token' => 'jwt-token-123'], 200);
            },
        ]);

        $pinned = $resolver->pin('openbao/openbao:2.6.1');

        expect($pinned)->toBe("openbao/openbao:2.6.1@{$expectedDigest}")
            ->and($headAttempts)->toBe(2);

        $mock->assertSentCount(3);
    });
});

test('resolve refuses a non-https WWW-Authenticate token realm', function (): void {
    templateImageDigestResolverWithMock(function (ImageDigestResolver $resolver): void {
        MockClient::global([
            HeadManifestRequest::class => MockResponse::make('', 401, [
                'WWW-Authenticate' => 'Bearer realm="http://auth.docker.io/token",service="registry.docker.io"',
            ]),
        ]);

        expect(fn () => $resolver->resolve('openbao/openbao:2.6.1'))
            ->toThrow(RuntimeException::class, 'HTTPS');
    });
});
