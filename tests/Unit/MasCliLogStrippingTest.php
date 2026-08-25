<?php

use App\Traits\InteractsWithChat;
use Symfony\Component\Yaml\Yaml;

function masCliLogStripper(): object
{
    return new class
    {
        use InteractsWithChat;

        public function strip(string $raw): string
        {
            return $this->stripMasCliLogLines($raw);
        }
    };
}

test('strips real mas-cli tracing output captured live 2026-08-24, leaving valid YAML', function (): void {
    // Exact shape confirmed live on chat-mas-chat-luchtech-dev-6fb7b6bdbf-p6ckm:
    // `kubectl logs` on the config-gen pod returned this whole blob as ONE
    // string — mas-cli's own log lines glued directly onto the real YAML
    // with no separating newline, which is what actually crashed the pod
    // ("invalid type: string ... http", expected a map").
    $raw = '2026-08-23T19:28:20.565906Z  INFO mas_config::sections::secrets:352 Generating keys...'
        .' 2026-08-23T19:28:21.284090Z  INFO mas_config::sections::secrets:359 Done generating RSA key'
        .' 2026-08-23T19:28:21.288207Z  INFO mas_config::sections::secrets:375 Done generating EC P-256 key'
        .' 2026-08-23T19:28:21.289811Z  INFO mas_config::sections::secrets:391 Done generating EC P-384 key'
        .' 2026-08-23T19:28:21.291404Z  INFO mas_config::sections::secrets:407 Done generating EC secp256k1 key'
        ." 2026-08-23T19:28:21.299348Z  INFO mas_cli::commands::config:118 Writing configuration to standard output\n"
        ."http:\n  listeners: []\nsecrets:\n  encryption: \"deadbeef\"\n";

    $stripped = masCliLogStripper()->strip($raw);
    $parsed = Yaml::parse($stripped);

    expect($parsed)->toBeArray()
        ->and($parsed['http']['listeners'])->toBe([])
        ->and($parsed['secrets']['encryption'])->toBe('deadbeef')
        ->and($stripped)->not->toContain('INFO')
        ->and($stripped)->not->toContain('Generating keys');
});

test('output with each log line on its own line strips cleanly too', function (): void {
    $raw = "2026-08-23T19:28:20.565906Z  INFO mas_config::sections::secrets:352 Generating keys...\n"
        ."2026-08-23T19:28:21.299348Z  INFO mas_cli::commands::config:118 Writing configuration to standard output\n"
        ."database:\n  uri: \"placeholder\"\n";

    $parsed = Yaml::parse(masCliLogStripper()->strip($raw));

    expect($parsed['database']['uri'])->toBe('placeholder');
});

test('clean input with no log lines at all passes through unchanged', function (): void {
    $clean = "http:\n  listeners: []\n";

    expect(Yaml::parse(masCliLogStripper()->strip($clean)))->toBe(['http' => ['listeners' => []]]);
});
