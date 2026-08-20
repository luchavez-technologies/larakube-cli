<?php

use App\Traits\InteractsWithMail;
use App\Traits\InteractsWithStalwartApi;
use Illuminate\Support\Facades\Process;

/**
 * stalwartEnsureApiKey() is shared bootstrap logic in InteractsWithStalwartApi,
 * called from stalwartAuthHeader() on the first admin call of a process when no
 * API key is stored yet — not specific to any one mail:* command, so it gets its
 * own file rather than living inside whichever command's test happened to
 * exercise it first.
 */
function apiKeyHarness(): object
{
    return new class
    {
        use InteractsWithMail;
        use InteractsWithStalwartApi;

        public function ensure(string $kubectl, string $ns): ?string
        {
            return $this->stalwartEnsureApiKey($kubectl, $ns);
        }
    };
}

test('stalwartEnsureApiKey mints and stores a key, creating the automation principal', function (): void {
    $callCount = 0;
    Process::fake([
        // No key yet → bootstrap; recovery admin available for the mint's basic auth.
        '*get secret mail-secrets*api-key*' => Process::result(output: '', exitCode: 1),
        '*get secret mail-secrets*admin-password*' => Process::result(output: base64_encode('recovery-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*patch secret mail-secrets*' => Process::result(output: 'patched'),
        '*exec *' => function () use (&$callCount) {
            $callCount++;

            return match ($callCount) {
                // stalwartAccounts: query(+empty get), then get(ids) — no automation principal
                1 => Process::result(output: '{"methodResponses":[["x:Account/query",{"ids":["c"]},"c0"],["x:Account/get",{"list":[]},"c1"]]}'),
                2 => Process::result(output: '{"methodResponses":[["x:Account/get",{"list":[{"id":"c","name":"admin"}]},"c1"]]}'),
                // stalwartDomains: query(+empty get), then get(ids)
                3 => Process::result(output: '{"methodResponses":[["x:Domain/query",{"ids":["b"]},"c0"],["x:Domain/get",{"list":[]},"c1"]]}'),
                4 => Process::result(output: '{"methodResponses":[["x:Domain/get",{"list":[{"id":"b","name":"luchtech.dev"}]},"c1"]]}'),
                // create the larakube-automation principal
                5 => Process::result(output: '{"methodResponses":[["x:Account/set",{"created":{"bot":{"id":"z"}}},"c1"]]}'),
                // mint the API key (server-generated secret)
                default => Process::result(output: '{"methodResponses":[["x:ApiKey/set",{"created":{"k1":{"id":"nk","secret":"API_MINTED"}}},"c1"]]}'),
            };
        },
    ]);

    expect(apiKeyHarness()->ensure('kubectl', 'larakube-shared'))->toBe('API_MINTED');
    Process::assertRan(fn ($p) => str_contains($p->command, 'patch secret mail-secrets'));
});
