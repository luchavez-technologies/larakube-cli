<?php

use App\Commands\Mail\MailCheckCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

/** hostMatchesCertName is private — reflection exercises the exact/wildcard logic the TLS identity check relies on. */
function mailCheckHostMatchesCertName(string $host, string $certName): bool
{
    $command = new MailCheckCommand;
    $method = new ReflectionMethod($command, 'hostMatchesCertName');
    $method->setAccessible(true);

    return $method->invoke($command, $host, $certName);
}

test('mail:check returns error json when no mail host is configured', function (): void {
    $exitCode = Artisan::call('mail:check', ['environment' => 'production', '--json' => true]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('"installed": false')
        ->toContain('No mail host configured');
});

test('mail:check emits json report when mail host is resolved', function (): void {
    Process::fake([
        '*get deployment*jsonpath*' => Process::result(output: '1'),
        '*get secret*stalwart-relay*' => Process::result(output: ''),
        '*dig*' => Process::result(output: ''),
        '*' => Process::result(),
    ]);

    Artisan::call('mail:check', ['environment' => 'local', '--json' => true]);
    $output = Artisan::output();

    $data = json_decode($output, true);
    expect($data)->toBeArray()
        ->and($data['installed'])->toBeTrue()
        ->and($data['host'])->toBe('send.test')
        ->and($data['checks'])->toBeArray();
});

test('a TLS cert identity check matches the exact host, a one-level wildcard, or neither', function (string $host, string $certName, bool $matches): void {
    expect(mailCheckHostMatchesCertName($host, $certName))->toBe($matches);
})->with([
    'exact match' => ['send.luchtech.dev', 'send.luchtech.dev', true],
    'case-insensitive exact match' => ['Send.Luchtech.Dev', 'send.luchtech.dev', true],
    'wildcard covers its own subdomain' => ['send.luchtech.dev', '*.luchtech.dev', true],
    // The real incident: Stalwart on a shared cluster presenting another tenant's cert.
    'another tenant entirely' => ['send.luchtech.dev', '*.ourfridays.com', false],
    'wildcard does not cover a different base domain' => ['send.luchtech.dev', '*.example.com', false],
    'wildcard does not cover two levels down' => ['a.b.luchtech.dev', '*.luchtech.dev', false],
    'unrelated exact name' => ['send.luchtech.dev', 'mail.luchtech.dev', false],
]);
