<?php

use App\Commands\Mail\MailCheckCommand;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

/**
 * mail:check no longer trusts the mere presence of the mail-relay secret — it
 * reaches through the Stalwart pod and actually SMTP-AUTHs against the relay.
 * These exercise probeRelay() directly (the network calls are all faked) so the
 * exact class of failure we hit on prod — blocked port, wrong Brevo key — is
 * caught by a green/red check instead of silently swallowing outbound mail.
 */
function invokeProbeRelay(): array
{
    $command = app(MailCheckCommand::class);
    $ref = new ReflectionMethod($command, 'probeRelay');
    $ref->setAccessible(true);

    return $ref->invoke($command, 'kubectl', 'larakube-shared');
}

/** Shared fakes: relay secret + admin auth + pod name + a wired brevo route. */
function fakeRelayEnv(string $opensslOutput, ?string $routeList = null): void
{
    $routeList ??= '[{"name":"brevo","address":"smtp-relay.brevo.com","port":2525,"implicitTls":false,"id":"rt1"}]';

    Process::fake([
        '*mail-relay*provider*' => Process::result(output: base64_encode('brevo')),
        '*mail-relay*username*' => Process::result(output: base64_encode('b262c1001@smtp-brevo.com')),
        '*mail-relay*password*' => Process::result(output: base64_encode('xsmtpsib-fullkey')),
        '*mail-secrets*' => Process::result(output: base64_encode('admin-pass')),
        '*port-forward*' => Process::result(output: ''),
        '*openssl s_client*' => Process::result(output: $opensslOutput),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        MockResponse::make([
            'methodResponses' => [
                ['x:MtaRoute/query', ['ids' => ['rt1']], 'c0'],
                ['x:MtaRoute/get', ['list' => json_decode($routeList, true), 'notFound' => []], 'c1'],
            ],
            'sessionState' => 'x',
        ]),
    ]);
}

test('probeRelay reports ok when the relay accepts AUTH (235)', function (): void {
    fakeRelayEnv("250-hello\r\n334 VXNlcm5hbWU6\r\n235 2.7.0 Authentication successful\r\n221 bye\r\n");

    [$status, $where, $hint] = invokeProbeRelay();

    expect($status)->toBe('ok')
        ->and($where)->toContain('smtp-relay.brevo.com:2525')
        ->toContain('authenticating');
});

test('probeRelay reports fail when the relay rejects the credentials (535)', function (): void {
    fakeRelayEnv("250-hello\r\n334 VXNlcm5hbWU6\r\n535 5.7.8 Authentication failed\r\n");

    [$status, $where, $hint] = invokeProbeRelay();

    expect($status)->toBe('fail')
        ->and($where)->toContain('535')
        ->and($hint)->toContain('xsmtpsib-');
});

test('probeRelay reports fail when the submission port is unreachable (no banner)', function (): void {
    fakeRelayEnv(''); // openssl timed out / no connection → empty output

    [$status, $where, $hint] = invokeProbeRelay();

    expect($status)->toBe('fail')
        ->and($where)->toContain('unreachable')
        ->and($hint)->toContain('2525');
});

test('probeRelay warns when the secret exists but Stalwart has no route', function (): void {
    fakeRelayEnv('235 ok', routeList: '[]'); // route query returns no matching route

    [$status, $where, $hint] = invokeProbeRelay();

    expect($status)->toBe('warn')
        ->and($where)->toContain('no Stalwart route');
});
