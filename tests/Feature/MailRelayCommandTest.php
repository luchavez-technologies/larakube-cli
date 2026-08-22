<?php

use App\Commands\Mail\MailRelayCommand;
use App\Traits\InteractsWithMail;
use App\Traits\InteractsWithStalwartApi;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('mail:relay is registered', function (): void {
    $this->artisan('list')
        ->assertExitCode(0)
        ->expectsOutputToContain('mail:relay');
});

test('mail:relay requires installed stalwart', function (): void {
    Process::fake(['*get deployment stalwart*' => Process::result(output: '', exitCode: 1)]);

    $this->artisan('mail:relay', ['--provider' => 'brevo', '--username' => 'a@b.com', '--api-key' => 'k'])
        ->assertExitCode(1)
        ->expectsOutputToContain('Stalwart is not installed');
});

test('mail:relay rejects an unknown provider', function (): void {
    Process::fake(['*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d')]);

    $this->artisan('mail:relay', ['--provider' => 'sendgrid'])
        ->assertExitCode(1)
        ->expectsOutputToContain("Unknown relay provider 'sendgrid'");
});

test('mail:relay wires brevo as the outbound relay', function (): void {
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-relay*' => Process::result(output: '', exitCode: 1),
        '*create secret generic mail-relay*' => Process::result(output: 'secret/mail-relay created'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*port-forward*' => Process::result(output: ''),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        MockResponse::make(['methodResponses' => [['x:MtaRoute/query', ['ids' => []], 'c0'], ['x:MtaRoute/get', ['list' => [], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [['x:MtaRoute/set', ['created' => ['r1' => ['id' => 'rt1']]], 'c1']], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [['x:MtaOutboundStrategy/get', ['list' => [['route' => ['match' => ['0' => ['if' => 'is_local_domain(rcpt_domain)', 'then' => "'local'"]], 'else' => "'mx'"], 'id' => 'singleton']], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [['x:MtaOutboundStrategy/set', ['updated' => ['singleton' => null]], 'c1']], 'sessionState' => 'x']),
        // match-set — the 1-arg is_local_domain rule above triggers the
        // malformed-rule repair (matchChanged = true).
        MockResponse::make(['methodResponses' => [['x:MtaOutboundStrategy/set', ['updated' => ['singleton' => null]], 'c2']], 'sessionState' => 'x']),
        // stalwartEnforceSingleRsaDkimSignature()'s query — no signatures.
        MockResponse::make(['methodResponses' => [['x:DkimSignature/query', ['ids' => []], 'c0']], 'sessionState' => 'x']),
    ]);

    $this->artisan('mail:relay', ['--provider' => 'brevo', '--username' => 'noreply@example.com', '--api-key' => 'xkeysib-test'])
        ->assertExitCode(0)
        ->expectsOutputToContain('Outbound mail now relays through Brevo')
        ->expectsOutputToContain('smtp-relay.brevo.com:2525')
        ->expectsOutputToContain('noreply@example.com')
        // Onboarding/pricing is for first-time interactive setup only — stay
        // quiet on a scripted run that already supplied both credentials.
        ->doesntExpectOutputToContain('Sign up free');
});

test('mail:relay wires SES with a region-scoped host on port 2587', function (): void {
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-relay*' => Process::result(output: '', exitCode: 1),
        '*create secret generic mail-relay*' => Process::result(output: 'secret/mail-relay created'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*port-forward*' => Process::result(output: ''),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        MockResponse::make(['methodResponses' => [['x:MtaRoute/query', ['ids' => []], 'c0'], ['x:MtaRoute/get', ['list' => [], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [['x:MtaRoute/set', ['created' => ['r1' => ['id' => 'rt1']]], 'c1']], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [['x:MtaOutboundStrategy/get', ['list' => [['route' => ['match' => ['0' => ['if' => 'is_local_domain(rcpt_domain)', 'then' => "'local'"]], 'else' => "'mx'"], 'id' => 'singleton']], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [['x:MtaOutboundStrategy/set', ['updated' => ['singleton' => null]], 'c1']], 'sessionState' => 'x']),
        // match-set — the 1-arg is_local_domain rule above triggers the
        // malformed-rule repair (matchChanged = true).
        MockResponse::make(['methodResponses' => [['x:MtaOutboundStrategy/set', ['updated' => ['singleton' => null]], 'c2']], 'sessionState' => 'x']),
        // stalwartEnforceSingleRsaDkimSignature()'s query — no signatures.
        MockResponse::make(['methodResponses' => [['x:DkimSignature/query', ['ids' => []], 'c0']], 'sessionState' => 'x']),
    ]);

    $this->artisan('mail:relay', ['--provider' => 'ses', '--region' => 'eu-west-1', '--username' => 'AKIAEXAMPLE', '--api-key' => 'ses-smtp-pass'])
        ->assertExitCode(0)
        ->expectsOutputToContain('Outbound mail now relays through Amazon SES')
        ->expectsOutputToContain('email-smtp.eu-west-1.amazonaws.com:2587')
        ->expectsOutputToContain('AKIAEXAMPLE');
});

test('mail:relay shows onboarding + pricing before prompting for fresh credentials', function (): void {
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-relay*' => Process::result(output: '', exitCode: 1),
        '*create secret generic mail-relay*' => Process::result(output: 'secret/mail-relay created'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*port-forward*' => Process::result(output: ''),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        MockResponse::make(['methodResponses' => [['x:MtaRoute/query', ['ids' => []], 'c0'], ['x:MtaRoute/get', ['list' => [], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [['x:MtaRoute/set', ['created' => ['r1' => ['id' => 'rt1']]], 'c1']], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [['x:MtaOutboundStrategy/get', ['list' => [['route' => ['match' => ['0' => ['if' => 'is_local_domain(rcpt_domain)', 'then' => "'local'"]], 'else' => "'mx'"], 'id' => 'singleton']], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [['x:MtaOutboundStrategy/set', ['updated' => ['singleton' => null]], 'c1']], 'sessionState' => 'x']),
        // match-set — the 1-arg is_local_domain rule above triggers the
        // malformed-rule repair (matchChanged = true).
        MockResponse::make(['methodResponses' => [['x:MtaOutboundStrategy/set', ['updated' => ['singleton' => null]], 'c2']], 'sessionState' => 'x']),
        // stalwartEnforceSingleRsaDkimSignature()'s query — no signatures.
        MockResponse::make(['methodResponses' => [['x:DkimSignature/query', ['ids' => []], 'c0']], 'sessionState' => 'x']),
    ]);

    Prompt::fake([
        'u', Key::ENTER, // username
        'k', Key::ENTER, // api-key
    ]);

    $command = app(MailRelayCommand::class);
    $input = new ArrayInput(['--provider' => 'brevo']);
    $input->bind($command->getDefinition());
    $input->setInteractive(true);
    $output = new BufferedOutput;
    $command->setInput($input);
    $command->setOutput(new OutputStyle($input, $output));

    $exitCode = $command->handle();

    expect($exitCode)->toBe(0);
    $printed = $output->fetch();
    expect($printed)->toContain('Sign up free')
        ->toContain('SMTP & API')
        ->toContain('Pricing:')
        ->toContain('300 emails/day');
});

test('mail:relay --remove is a no-op when no relay route exists', function (): void {
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*port-forward*' => Process::result(output: ''),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        MockResponse::make(['methodResponses' => [['x:MtaRoute/query', ['ids' => []], 'c0'], ['x:MtaRoute/get', ['list' => [], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
    ]);

    $this->artisan('mail:relay', ['--provider' => 'brevo', '--remove' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('No Brevo relay route is configured');
});

test('mail:relay --remove reverts to MX and deletes the route', function (): void {
    Process::fake([
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*port-forward*' => Process::result(output: ''),
        '*delete secret mail-relay*' => Process::result(output: 'secret "mail-relay" deleted'),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        MockResponse::make(['methodResponses' => [['x:MtaRoute/query', ['ids' => ['rt1']], 'c0'], ['x:MtaRoute/get', ['list' => [['id' => 'rt1', 'name' => 'brevo', '@type' => 'Relay']], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [['x:MtaOutboundStrategy/get', ['list' => [['route' => ['match' => [], 'else' => "'brevo'"], 'id' => 'singleton']], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [['x:MtaOutboundStrategy/set', ['updated' => ['singleton' => null]], 'c1']], 'sessionState' => 'x']),
        MockResponse::make(['methodResponses' => [['x:MtaRoute/set', ['destroyed' => ['rt1']], 'c1']], 'sessionState' => 'x']),
    ]);

    $this->artisan('mail:relay', ['--provider' => 'brevo', '--remove' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('Outbound mail now delivers directly via MX again')
        ->expectsOutputToContain('Clean up the DNS records Brevo added')
        ->expectsOutputToContain('brevo1._domainkey');
});

test('stalwartSetOutboundRoute sets outbound strategy route', function (): void {
    Process::fake([
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*port-forward*' => Process::result(output: ''),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        MockResponse::make(['methodResponses' => [['x:MtaOutboundStrategy/get', ['list' => [['route' => ['match' => ['0' => ['if' => 'is_local_domain(rcpt_domain)', 'then' => "'local'"]], 'else' => "'mx'"], 'id' => 'singleton']], 'notFound' => []], 'c1']], 'sessionState' => 'x']),
        // else-set, always sent.
        MockResponse::make(['methodResponses' => [['x:MtaOutboundStrategy/set', ['updated' => ['singleton' => null]], 'c1']], 'sessionState' => 'x']),
        // match-set — sent too, since the 1-arg is_local_domain rule above
        // triggers the malformed-rule repair (matchChanged = true).
        MockResponse::make(['methodResponses' => [['x:MtaOutboundStrategy/set', ['updated' => ['singleton' => null]], 'c2']], 'sessionState' => 'x']),
    ]);

    $trait = new class
    {
        use InteractsWithMail;
        use InteractsWithStalwartApi;

        public function setRoute(string $kubectl, string $ns, string $routeName): bool
        {
            return $this->stalwartSetOutboundRoute($kubectl, $ns, $routeName);
        }
    };

    $ok = $trait->setRoute('kubectl', 'larakube-shared', 'ses');
    expect($ok)->toBeTrue();
});

/**
 * Captures the `match` array stalwartSetOutboundRoute() actually PUTs onto
 * x:MtaOutboundStrategy/set, given an existing `match`/`else` fixture — same
 * intent as the old Process-command-payload-scraping helper, just reading
 * the Saloon request body directly instead of parsing a shelled-out curl
 * command's stdin redirect.
 */
function captureRoutePatch(array $existingMatch, string $routeName, string $existingElse = "'mx'"): array
{
    Process::fake([
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*port-forward*' => Process::result(output: ''),
        '*' => Process::result(),
    ]);

    $setPayload = null;
    $captureSetCall = function ($pendingRequest) use (&$setPayload) {
        // Normalize through JSON — some fields are stdClass in memory,
        // cast to plain arrays by encoding/decoding.
        $payload = json_decode(json_encode($pendingRequest->getRequest()->body()->all()), true);

        if (($payload['methodCalls'][0][0] ?? '') === 'x:MtaOutboundStrategy/set'
            && isset($payload['methodCalls'][0][1]['update']['singleton']['route']['match'])) {
            $setPayload = $payload;
        }

        return MockResponse::make(['methodResponses' => [['x:MtaOutboundStrategy/set', ['updated' => ['singleton' => null]], 'c1']], 'sessionState' => 'x']);
    };

    Saloon::fake([
        MockResponse::make(['methodResponses' => [[
            'x:MtaOutboundStrategy/get',
            ['list' => [['route' => ['match' => $existingMatch, 'else' => $existingElse], 'id' => 'singleton']], 'notFound' => []],
            'c1',
        ]], 'sessionState' => 'x']),
        // Two possible /set calls follow (else, then match — only when the
        // malformed-rule repair actually changed something); the same
        // closure serves either slot since it filters by shape itself.
        $captureSetCall,
        $captureSetCall,
    ]);

    $trait = new class
    {
        use InteractsWithMail;
        use InteractsWithStalwartApi;

        public function setRoute(string $kubectl, string $ns, string $routeName): bool
        {
            return $this->stalwartSetOutboundRoute($kubectl, $ns, $routeName);
        }
    };

    $trait->setRoute('kubectl', 'larakube-shared', $routeName);

    return (array) ($setPayload['methodCalls'][0][1]['update']['singleton']['route']['match'] ?? []);
}

test('the local-domain rule uses Stalwart\'s 1-argument is_local_domain', function (): void {
    $match = captureRoutePatch(
        ['0' => ['if' => 'is_local_domain(rcpt_domain)', 'then' => "'local'"]],
        'ses',
    );

    $localRule = collect($match)->first(fn ($r) => str_contains((string) ($r['if'] ?? ''), 'is_local_domain'));

    expect($localRule['if'])->toBe('is_local_domain(rcpt_domain)');
});
