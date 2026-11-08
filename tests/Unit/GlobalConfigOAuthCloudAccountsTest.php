<?php

use App\Data\GlobalConfigData;
use App\Services\Cloud\OAuthTokenResult;

/** ADR 0029: OAuth-connected accounts dedupe on the provider's own identity, never a name the user typed. */
function oauthResult(string $accessToken = 'access-1', ?string $refreshToken = 'refresh-1'): OAuthTokenResult
{
    return new OAuthTokenResult($accessToken, $refreshToken, now()->addHour());
}

test('the first OAuth sign-in for a provider becomes the default account', function (): void {
    $config = new GlobalConfigData;

    $id = $config->upsertOAuthCloudAccount('do', 'do-uuid-1', 'DigitalOcean — a@example.com', oauthResult());

    $accounts = $config->getCloudAccounts('do');

    expect($accounts)->toHaveCount(1)
        ->and($accounts[0]['id'])->toBe($id)
        ->and($accounts[0]['default'])->toBeTrue()
        ->and($accounts[0]['authMethod'])->toBe('oauth')
        ->and($accounts[0]['providerAccountId'])->toBe('do-uuid-1')
        ->and($accounts[0]['refreshToken'])->toBe('refresh-1')
        ->and($config->getDoToken())->toBe('access-1');
});

test('signing in with the SAME provider account updates that entry in place, never duplicating it', function (): void {
    $config = new GlobalConfigData;
    $id = $config->upsertOAuthCloudAccount('do', 'do-uuid-1', 'DigitalOcean — a@example.com', oauthResult('access-1'));

    $sameId = $config->upsertOAuthCloudAccount('do', 'do-uuid-1', 'DigitalOcean — a@example.com', oauthResult('access-2', 'refresh-2'));

    $accounts = $config->getCloudAccounts('do');

    expect($sameId)->toBe($id)
        ->and($accounts)->toHaveCount(1)
        ->and($accounts[0]['token'])->toBe('access-2')
        ->and($accounts[0]['refreshToken'])->toBe('refresh-2');
});

test('signing in with a DIFFERENT provider account creates a separate, non-default entry', function (): void {
    $config = new GlobalConfigData;
    $id1 = $config->upsertOAuthCloudAccount('do', 'do-uuid-1', 'DigitalOcean — personal@example.com', oauthResult());
    $id2 = $config->upsertOAuthCloudAccount('do', 'do-uuid-2', 'DigitalOcean — agency@example.com', oauthResult());

    $accounts = $config->getCloudAccounts('do');

    expect($accounts)->toHaveCount(2)
        ->and($id1)->not->toBe($id2)
        ->and(collect($accounts)->firstWhere('id', $id1)['default'])->toBeTrue()
        ->and(collect($accounts)->firstWhere('id', $id2)['default'])->toBeFalse()
        // the mirrored single-slot field still reflects whichever account is default
        ->and($config->getDoToken())->toBe(collect($accounts)->firstWhere('id', $id1)['token']);
});

test('refreshing a non-default account does not disturb which account is default', function (): void {
    $config = new GlobalConfigData;
    $id1 = $config->upsertOAuthCloudAccount('do', 'do-uuid-1', 'Personal', oauthResult('access-1'));
    $id2 = $config->upsertOAuthCloudAccount('do', 'do-uuid-2', 'Agency', oauthResult('access-2'));

    $config->upsertOAuthCloudAccount('do', 'do-uuid-2', 'Agency', oauthResult('access-2-refreshed'));

    expect($config->getDefaultCloudAccount('do')['id'])->toBe($id1)
        ->and($config->getDoToken())->toBe('access-1')
        ->and(collect($config->getCloudAccounts('do'))->firstWhere('id', $id2)['token'])->toBe('access-2-refreshed');
});

test('upsertOAuthCloudAccount works the same way for cloudflare as it does for do', function (): void {
    $config = new GlobalConfigData;

    $config->upsertOAuthCloudAccount('cloudflare', 'cf-user-id-1', 'Cloudflare — a@example.com', oauthResult());

    $accounts = $config->getCloudAccounts('cloudflare');

    expect($accounts)->toHaveCount(1)
        ->and($accounts[0]['providerAccountId'])->toBe('cf-user-id-1')
        ->and($accounts[0]['authMethod'])->toBe('oauth')
        ->and($config->getCloudflareToken())->toBe('access-1');
});

test('a manually-pasted account (addCloudAccount) and an OAuth account can coexist for the same provider', function (): void {
    $config = new GlobalConfigData;
    $pastedId = $config->addCloudAccount('do', 'Pasted token', 'dop_v1_pasted', asDefault: true);
    $oauthId = $config->upsertOAuthCloudAccount('do', 'do-uuid-1', 'OAuth account', oauthResult());

    $accounts = $config->getCloudAccounts('do');

    expect($accounts)->toHaveCount(2)
        ->and(collect($accounts)->firstWhere('id', $pastedId)['authMethod'] ?? null)->toBeNull()
        ->and(collect($accounts)->firstWhere('id', $oauthId)['authMethod'])->toBe('oauth');
});
