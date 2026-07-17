<?php

use Illuminate\Support\Facades\Process;

test('mail:relay is registered', function () {
    $this->artisan('list')
        ->assertExitCode(0)
        ->expectsOutputToContain('mail:relay');
});

test('mail:relay requires installed stalwart', function () {
    Process::fake(['*get statefulset stalwart*' => Process::result(output: '', exitCode: 1)]);

    $this->artisan('mail:relay', ['provider' => 'brevo', '--username' => 'a@b.com', '--api-key' => 'k'])
        ->assertExitCode(1)
        ->expectsOutputToContain('Stalwart is not installed');
});

test('mail:relay rejects an unknown provider', function () {
    Process::fake(['*get statefulset stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d')]);

    $this->artisan('mail:relay', ['provider' => 'sendgrid'])
        ->assertExitCode(1)
        ->expectsOutputToContain("Unknown relay provider 'sendgrid'");
});

test('mail:relay wires brevo as the outbound relay', function () {
    $callCount = 0;
    Process::fake([
        '*get statefulset stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-relay*' => Process::result(output: '', exitCode: 1),
        '*create secret generic mail-relay*' => Process::result(output: 'secret/mail-relay created'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*' => function () use (&$callCount) {
            $callCount++;

            return match ($callCount) {
                1 => Process::result(output: '{"methodResponses":[["x:MtaRoute/query",{"ids":[]},"c0"],["x:MtaRoute/get",{"list":[],"notFound":[]},"c1"]],"sessionState":"x"}'),
                2 => Process::result(output: '{"methodResponses":[["x:MtaRoute/set",{"created":{"r1":{"id":"rt1"}}},"c1"]],"sessionState":"x"}'),
                3 => Process::result(output: '{"methodResponses":[["x:MtaOutboundStrategy/get",{"list":[{"route":{"match":{"0":{"if":"is_local_domain(rcpt_domain)","then":"\'local\'"}},"else":"\'mx\'"},"id":"singleton"}],"notFound":[]},"c1"]],"sessionState":"x"}'),
                default => Process::result(output: '{"methodResponses":[["x:MtaOutboundStrategy/set",{"updated":{"singleton":null}},"c1"]],"sessionState":"x"}'),
            };
        },
    ]);

    $this->artisan('mail:relay', ['provider' => 'brevo', '--username' => 'noreply@example.com', '--api-key' => 'xkeysib-test'])
        ->assertExitCode(0)
        ->expectsOutputToContain('Outbound mail now relays through Brevo')
        ->expectsOutputToContain('smtp-relay.brevo.com:2525')
        ->expectsOutputToContain('noreply@example.com')
        // Onboarding/pricing is for first-time interactive setup only — stay
        // quiet on a scripted run that already supplied both credentials.
        ->doesntExpectOutputToContain('Sign up free');
});

test('mail:relay wires SES with a region-scoped host on port 2587', function () {
    $callCount = 0;
    Process::fake([
        '*get statefulset stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-relay*' => Process::result(output: '', exitCode: 1),
        '*create secret generic mail-relay*' => Process::result(output: 'secret/mail-relay created'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*' => function () use (&$callCount) {
            $callCount++;

            return match ($callCount) {
                1 => Process::result(output: '{"methodResponses":[["x:MtaRoute/query",{"ids":[]},"c0"],["x:MtaRoute/get",{"list":[],"notFound":[]},"c1"]],"sessionState":"x"}'),
                2 => Process::result(output: '{"methodResponses":[["x:MtaRoute/set",{"created":{"r1":{"id":"rt1"}}},"c1"]],"sessionState":"x"}'),
                3 => Process::result(output: '{"methodResponses":[["x:MtaOutboundStrategy/get",{"list":[{"route":{"match":{"0":{"if":"is_local_domain(rcpt_domain)","then":"\'local\'"}},"else":"\'mx\'"},"id":"singleton"}],"notFound":[]},"c1"]],"sessionState":"x"}'),
                default => Process::result(output: '{"methodResponses":[["x:MtaOutboundStrategy/set",{"updated":{"singleton":null}},"c1"]],"sessionState":"x"}'),
            };
        },
    ]);

    $this->artisan('mail:relay', ['provider' => 'ses', '--region' => 'eu-west-1', '--username' => 'AKIAEXAMPLE', '--api-key' => 'ses-smtp-pass'])
        ->assertExitCode(0)
        ->expectsOutputToContain('Outbound mail now relays through Amazon SES')
        ->expectsOutputToContain('email-smtp.eu-west-1.amazonaws.com:2587')
        ->expectsOutputToContain('AKIAEXAMPLE');
});

test('mail:relay shows onboarding + pricing before prompting for fresh credentials', function () {
    $callCount = 0;
    Process::fake([
        '*get statefulset stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-relay*' => Process::result(output: '', exitCode: 1),
        '*create secret generic mail-relay*' => Process::result(output: 'secret/mail-relay created'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*' => function () use (&$callCount) {
            $callCount++;

            return match ($callCount) {
                1 => Process::result(output: '{"methodResponses":[["x:MtaRoute/query",{"ids":[]},"c0"],["x:MtaRoute/get",{"list":[],"notFound":[]},"c1"]],"sessionState":"x"}'),
                2 => Process::result(output: '{"methodResponses":[["x:MtaRoute/set",{"created":{"r1":{"id":"rt1"}}},"c1"]],"sessionState":"x"}'),
                3 => Process::result(output: '{"methodResponses":[["x:MtaOutboundStrategy/get",{"list":[{"route":{"match":{"0":{"if":"is_local_domain(rcpt_domain)","then":"\'local\'"}},"else":"\'mx\'"},"id":"singleton"}],"notFound":[]},"c1"]],"sessionState":"x"}'),
                default => Process::result(output: '{"methodResponses":[["x:MtaOutboundStrategy/set",{"updated":{"singleton":null}},"c1"]],"sessionState":"x"}'),
            };
        },
    ]);

    Laravel\Prompts\Prompt::fake([
        'u', Laravel\Prompts\Key::ENTER, // username
        'k', Laravel\Prompts\Key::ENTER, // api-key
    ]);

    $command = app(App\Commands\Mail\MailRelayCommand::class);
    $input = new Symfony\Component\Console\Input\ArrayInput(['provider' => 'brevo']);
    $input->bind($command->getDefinition());
    $input->setInteractive(true);
    $output = new Symfony\Component\Console\Output\BufferedOutput;
    $command->setInput($input);
    $command->setOutput(new Illuminate\Console\OutputStyle($input, $output));

    $exitCode = $command->handle();

    expect($exitCode)->toBe(0);
    $printed = $output->fetch();
    expect($printed)->toContain('Sign up free')
        ->toContain('SMTP & API')
        ->toContain('Pricing:')
        ->toContain('300 emails/day');
});

test('mail:relay --remove is a no-op when no relay route exists', function () {
    Process::fake([
        '*get statefulset stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*' => Process::result(output: '{"methodResponses":[["x:MtaRoute/query",{"ids":[]},"c0"],["x:MtaRoute/get",{"list":[],"notFound":[]},"c1"]],"sessionState":"x"}'),
    ]);

    $this->artisan('mail:relay', ['provider' => 'brevo', '--remove' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('No Brevo relay route is configured');
});

test('mail:relay --remove reverts to MX and deletes the route', function () {
    $callCount = 0;
    Process::fake([
        '*get statefulset stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*delete secret mail-relay*' => Process::result(output: 'secret "mail-relay" deleted'),
        '*' => function () use (&$callCount) {
            $callCount++;

            return match ($callCount) {
                1 => Process::result(output: '{"methodResponses":[["x:MtaRoute/query",{"ids":["rt1"]},"c0"],["x:MtaRoute/get",{"list":[{"id":"rt1","name":"brevo","@type":"Relay"}],"notFound":[]},"c1"]],"sessionState":"x"}'),
                2 => Process::result(output: '{"methodResponses":[["x:MtaOutboundStrategy/get",{"list":[{"route":{"match":{},"else":"\'brevo\'"},"id":"singleton"}],"notFound":[]},"c1"]],"sessionState":"x"}'),
                3 => Process::result(output: '{"methodResponses":[["x:MtaOutboundStrategy/set",{"updated":{"singleton":null}},"c1"]],"sessionState":"x"}'),
                default => Process::result(output: '{"methodResponses":[["x:MtaRoute/set",{"destroyed":["rt1"]},"c1"]],"sessionState":"x"}'),
            };
        },
    ]);

    $this->artisan('mail:relay', ['provider' => 'brevo', '--remove' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('Outbound mail now delivers directly via MX again')
        ->expectsOutputToContain('Clean up the DNS records Brevo added')
        ->expectsOutputToContain('brevo1._domainkey');
});
