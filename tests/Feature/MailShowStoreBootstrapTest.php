<?php

use Illuminate\Support\Facades\Process;

test('mail:show detects a local wizard-skip install and shows "already configured" instead of wizard instructions', function (): void {
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   1d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*get configmap stalwart-config*' => Process::result(output: 'stalwart-config   1   1d'),
        '*get configmap plex-commons*' => json_encode([
            'version' => 1,
            'services' => ['postgres' => ['enabled' => true]],
        ]),
        '*exec *' => function ($process) {
            $cmd = is_string($process->command) ? $process->command : implode(' ', (array) $process->command);
            // Matches the stdin redirect on ANY exec'd command, not a specific
            // temp-filename prefix — stalwartJmap()'s scratch file lives in its
            // own Spatie\TemporaryDirectory now. A non-JMAP exec's redirect
            // (e.g. a piped SQL dump) is filtered out by the $payload === null
            // check below, since json_decode() on non-JSON content returns null.
            if (! preg_match("!< '([^']+)'!", $cmd, $m) || ! file_exists($m[1])) {
                return Process::result(output: 'success');
            }

            $payload = json_decode(file_get_contents($m[1]), true);
            if ($payload === null) {
                return Process::result(output: 'success');
            }

            $method = $payload['methodCalls'][0][0] ?? '';

            $store = match ($method) {
                'x:BlobStore/get' => ['@type' => 'S3', 'region' => ['customEndpoint' => 'http://seaweedfs.larakube-plex.svc.cluster.local:8333']],
                'x:InMemoryStore/get' => ['@type' => 'Redis'],
                'x:SearchStore/get' => ['@type' => 'Meilisearch'],
                default => null,
            };

            return Process::result(output: json_encode([
                'methodResponses' => [[$method, ['list' => $store !== null ? [$store] : []], 'c1']],
            ]));
        },
        '*' => Process::result(),
    ]);

    $this->artisan('mail:show')
        ->assertExitCode(0)
        ->expectsOutputToContain('already configured')
        ->doesntExpectOutputToContain('replace Stalwart\'s embedded RocksDB');
});

test('mail:show falls back to the original wizard hint when stalwart-config does not exist', function (): void {
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   1d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*get configmap stalwart-config*' => Process::result(output: '', exitCode: 1),
        '*get configmap plex-commons*' => json_encode([
            'version' => 1,
            'services' => ['postgres' => ['enabled' => true]],
        ]),
        '*' => Process::result(),
    ]);

    $this->artisan('mail:show')
        ->assertExitCode(0)
        ->expectsOutputToContain('replace Stalwart\'s embedded RocksDB');
});
