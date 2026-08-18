<?php

use Illuminate\Support\Facades\Process;

/**
 * Extract and JSON-decode the JMAP payload written to a temp file by stalwartJmap().
 * Mirrors MailRelayCommandTest's helper — kept separate here (a global function
 * declared in two loaded test files would fatal on redeclaration).
 *
 * @return array<string, mixed>|null
 */
function stalwartStoreBootstrapJmapPayload(mixed $process): ?array
{
    $cmd = is_string($process->command)
        ? $process->command
        : implode(' ', (array) $process->command);

    if (preg_match("!< '([^']+larakube_stalwart[^']+)'!", $cmd, $m) && file_exists($m[1])) {
        return json_decode(file_get_contents($m[1]), true) ?: null;
    }

    return null;
}

test('mail:init local wires BlobStore, InMemoryStore, and SearchStore via JMAP when Commons offers seaweedfs, redis, and meilisearch', function () {
    $captured = [];

    Process::fake([
        '*get configmap plex-commons*' => json_encode([
            'version' => 1,
            'services' => [
                'postgres' => ['enabled' => true],
                'redis' => ['enabled' => true],
                'seaweedfs' => ['enabled' => true],
                'meilisearch' => ['enabled' => true],
            ],
        ]),
        '*get secret plex-admin*S3_ACCESS_KEY*' => Process::result(output: base64_encode('larakube')),
        '*get secret plex-admin*S3_SECRET_KEY*' => Process::result(output: base64_encode('s3-secret')),
        '*get secret plex-admin*MEILI_MASTER_KEY*' => Process::result(output: base64_encode('meili-secret')),
        '*get secret mail-secrets*api-key*' => Process::result(output: base64_encode('already-minted-key')),
        '*get secret mail-secrets*' => Process::result(output: '', exitCode: 1),
        '*get secret openbao-bootstrap*' => Process::result(output: '', exitCode: 1),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*create secret generic mail-secrets*' => Process::result(output: 'secret created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   1s'),
        '*exec *' => function ($process) use (&$captured) {
            $payload = stalwartStoreBootstrapJmapPayload($process);

            if ($payload === null) {
                // Non-JMAP exec: Commons DB allocation / bucket creation.
                return Process::result(output: 'success');
            }

            $method = $payload['methodCalls'][0][0] ?? '';
            $captured[$method] = $payload['methodCalls'][0][1] ?? [];

            return match ($method) {
                'x:BlobStore/set', 'x:InMemoryStore/set', 'x:SearchStore/set', 'x:Http/set' => Process::result(
                    output: json_encode(['methodResponses' => [[$method, ['updated' => ['singleton' => null]], 'c1']]]),
                ),
                default => Process::result(
                    output: json_encode(['methodResponses' => [[$method, ['created' => ['r1' => ['id' => 'a1']]], 'c1']]]),
                ),
            };
        },
        '*' => Process::result(),
    ]);

    $this->artisan('mail:init local --admin-email=admin@luchtech.dev --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('Stalwart mail server is live.')
        // Every store Commons offers got auto-configured (real-time ✔ above)
        // — printPlexHint()'s "already configured" section would be pure
        // noise here, so mail:init skips it entirely in this case.
        ->doesntExpectOutputToContain('Configure remaining stores');

    expect($captured)->toHaveKeys(['x:BlobStore/set', 'x:InMemoryStore/set', 'x:SearchStore/set']);

    $blob = $captured['x:BlobStore/set']['update']['singleton'];
    expect($blob['@type'])->toBe('S3')
        ->and($blob['bucket'])->toBe('stalwart')
        ->and($blob['accessKey'])->toBe('larakube')
        ->and($blob['secretKey'])->toBe(['@type' => 'EnvironmentVariable', 'variableName' => 'STALWART_S3_SECRET_KEY'])
        ->and($blob['region'])->toBe(['@type' => 'Custom', 'customEndpoint' => 'http://seaweedfs.larakube-plex.svc.cluster.local:8333', 'customRegion' => 'us-east-1']);

    $redis = $captured['x:InMemoryStore/set']['update']['singleton'];
    expect($redis)->toBe(['@type' => 'Redis', 'url' => 'redis://redis.larakube-plex.svc.cluster.local:6379/0']);

    $search = $captured['x:SearchStore/set']['update']['singleton'];
    expect($search['@type'])->toBe('Meilisearch')
        ->and($search['url'])->toBe('http://meilisearch.larakube-plex.svc.cluster.local:7700')
        ->and($search['httpAuth'])->toBe(['@type' => 'Bearer', 'bearerToken' => ['@type' => 'EnvironmentVariable', 'variableName' => 'STALWART_SEARCH_MEILI_KEY']]);
});

test('mail:init local falls back to SearchStore "Default" (reuse Data store) when Meilisearch is not enabled', function () {
    $captured = [];

    Process::fake([
        '*get configmap plex-commons*' => json_encode([
            'version' => 1,
            'services' => ['postgres' => ['enabled' => true]],
        ]),
        '*get secret mail-secrets*api-key*' => Process::result(output: base64_encode('already-minted-key')),
        '*get secret mail-secrets*' => Process::result(output: '', exitCode: 1),
        '*get secret openbao-bootstrap*' => Process::result(output: '', exitCode: 1),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*create secret generic mail-secrets*' => Process::result(output: 'secret created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   1s'),
        '*exec *' => function ($process) use (&$captured) {
            $payload = stalwartStoreBootstrapJmapPayload($process);

            if ($payload === null) {
                return Process::result(output: 'success');
            }

            $method = $payload['methodCalls'][0][0] ?? '';
            $captured[$method] = $payload['methodCalls'][0][1] ?? [];

            return Process::result(
                output: json_encode(['methodResponses' => [[$method, ['updated' => ['singleton' => null]], 'c1']]]),
            );
        },
        '*' => Process::result(),
    ]);

    $this->artisan('mail:init local --admin-email=admin@luchtech.dev --no-interaction')
        ->assertExitCode(0);

    expect($captured)->toHaveKey('x:SearchStore/set')
        ->and($captured)->not->toHaveKeys(['x:BlobStore/set', 'x:InMemoryStore/set']);

    expect($captured['x:SearchStore/set']['update']['singleton'])->toBe(['@type' => 'Default']);
});
