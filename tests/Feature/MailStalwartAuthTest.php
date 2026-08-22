<?php

use App\Traits\InteractsWithMail;
use App\Traits\InteractsWithStalwartApi;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

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
    Process::fake([
        // No key yet → bootstrap; recovery admin available for the mint's basic auth.
        '*get secret mail-secrets*api-key*' => Process::result(output: '', exitCode: 1),
        '*get secret mail-secrets*admin-password*' => Process::result(output: base64_encode('recovery-pass')),
        '*port-forward*' => Process::result(output: ''),
        '*patch secret mail-secrets*' => Process::result(output: 'patched'),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        // stalwartAccounts: query(+empty get), then get(ids) — no automation principal
        MockResponse::make(['methodResponses' => [['x:Account/query', ['ids' => ['c']], 'c0'], ['x:Account/get', ['list' => []], 'c1']]]),
        MockResponse::make(['methodResponses' => [['x:Account/get', ['list' => [['id' => 'c', 'name' => 'admin']]], 'c1']]]),
        // stalwartDomains: query(+empty get), then get(ids)
        MockResponse::make(['methodResponses' => [['x:Domain/query', ['ids' => ['b']], 'c0'], ['x:Domain/get', ['list' => []], 'c1']]]),
        MockResponse::make(['methodResponses' => [['x:Domain/get', ['list' => [['id' => 'b', 'name' => 'luchtech.dev']]], 'c1']]]),
        // create the larakube-automation principal
        MockResponse::make(['methodResponses' => [['x:Account/set', ['created' => ['bot' => ['id' => 'z']]], 'c1']]]),
        // mint the API key (server-generated secret)
        MockResponse::make(['methodResponses' => [['x:ApiKey/set', ['created' => ['k1' => ['id' => 'nk', 'secret' => 'API_MINTED']]], 'c1']]]),
    ]);

    expect(apiKeyHarness()->ensure('kubectl', 'larakube-shared'))->toBe('API_MINTED');
    Process::assertRan(fn ($p) => str_contains($p->command, 'patch secret mail-secrets'));
});
