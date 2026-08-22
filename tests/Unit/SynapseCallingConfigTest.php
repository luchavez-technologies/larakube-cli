<?php

use App\Traits\InteractsWithChat;
use Symfony\Component\Yaml\Yaml;

function callingRenderer(): object
{
    return new class
    {
        use InteractsWithChat;

        public function render(string $yaml, ?string $url, ?string $masPublicIssuer = null): string
        {
            return $this->renderSynapseCalling($yaml, $url, $masPublicIssuer);
        }
    };
}

const BASE_HOMESERVER = <<<'YAML'
server_name: "chat.example.com"
report_stats: false
email:
  enable_notifs: true
  notif_from: "noreply@example.com"

YAML;

test('an inert well_known block left by an older render is stripped, not preserved', function (): void {
    // Older templates wrote `well_known:`, which Synapse ignores entirely.
    // Leaving it behind next to the real key is a trap for the next reader.
    $legacy = BASE_HOMESERVER."well_known:\n  client:\n    \"org.matrix.msc4143.rtc_foci\": []\n";

    $parsed = Yaml::parse(callingRenderer()->render($legacy, 'https://meet.example.com/jwt'));

    expect($parsed)->not->toHaveKey('well_known')
        ->and($parsed['extra_well_known_client_content']['org.matrix.msc4143.rtc_foci'][0]['livekit_service_url'])
        ->toBe('https://meet.example.com/jwt');
});

test('wiring writes the whole calling block, not just the focus URL', function (): void {
    // Writing the focus alone would point Element Call at a working SFU while
    // leaving MSC4140 off — the exact config that made clients rejoin every 15s.
    $parsed = Yaml::parse(callingRenderer()->render(BASE_HOMESERVER, 'https://meet.example.com/jwt'));

    expect($parsed['experimental_features']['msc4140_enabled'])->toBeTrue()
        ->and($parsed['max_event_delay_duration'])->toBe('24h')
        ->and($parsed['rc_message']['burst_count'])->toBe(30)
        ->and($parsed['rc_delayed_event_mgmt']['burst_count'])->toBe(20)
        ->and($parsed)->not->toHaveKey('well_known')
        ->and($parsed['extra_well_known_client_content']['org.matrix.msc4143.rtc_foci'][0]['livekit_service_url'])
        ->toBe('https://meet.example.com/jwt');
});

test('unwiring strips every part of the calling block', function (): void {
    $wired = callingRenderer()->render(BASE_HOMESERVER, 'https://meet.example.com/jwt');
    $parsed = Yaml::parse(callingRenderer()->render($wired, null));

    expect($parsed)->not->toHaveKey('experimental_features')
        ->and($parsed)->not->toHaveKey('max_event_delay_duration')
        ->and($parsed)->not->toHaveKey('rc_message')
        ->and($parsed)->not->toHaveKey('rc_delayed_event_mgmt')
        ->and($parsed)->not->toHaveKey('well_known')
        ->and($parsed)->not->toHaveKey('extra_well_known_client_content');
});

test('rewriting the calling block preserves unrelated config', function (): void {
    // meet:wire edits the live homeserver.yaml in place; clobbering the mail or
    // database blocks would take Synapse down rather than just calling.
    $parsed = Yaml::parse(callingRenderer()->render(BASE_HOMESERVER, 'https://meet.example.com/jwt'));

    expect($parsed['server_name'])->toBe('chat.example.com')
        ->and($parsed['report_stats'])->toBeFalse()
        ->and($parsed['email']['notif_from'])->toBe('noreply@example.com');
});

test('re-wiring is idempotent — the block is replaced, never duplicated', function (): void {
    $once = callingRenderer()->render(BASE_HOMESERVER, 'https://meet.example.com/jwt');
    $twice = callingRenderer()->render($once, 'https://meet.example.com/jwt');

    expect($twice)->toBe($once)
        ->and(substr_count($twice, 'extra_well_known_client_content:'))->toBe(1);
});

test('re-wiring to a different Meet host replaces the old focus', function (): void {
    $first = callingRenderer()->render(BASE_HOMESERVER, 'https://meet.example.com/jwt');
    $parsed = Yaml::parse(callingRenderer()->render($first, 'https://meet.other.com/jwt'));

    $foci = $parsed['extra_well_known_client_content']['org.matrix.msc4143.rtc_foci'];

    expect($foci)->toHaveCount(1)
        ->and($foci[0]['livekit_service_url'])->toBe('https://meet.other.com/jwt');
});

test('MAS auth-discovery key and Meet focus coexist under the same shared well-known key', function (): void {
    // extra_well_known_client_content is the ONE thing meet:wire and
    // ChatInitCommand's activateMasAuthMode() both write into — this is the
    // "must not clobber each other" invariant both of them depend on.
    $parsed = Yaml::parse(callingRenderer()->render(
        BASE_HOMESERVER,
        'https://meet.example.com/jwt',
        'https://mas.chat.example.com/',
    ));

    expect($parsed['extra_well_known_client_content']['org.matrix.msc4143.rtc_foci'][0]['livekit_service_url'])
        ->toBe('https://meet.example.com/jwt')
        ->and($parsed['extra_well_known_client_content']['org.matrix.msc2965.authentication']['issuer'])
        ->toBe('https://mas.chat.example.com/')
        ->and($parsed['extra_well_known_client_content']['org.matrix.msc2965.authentication']['account'])
        ->toBe('https://mas.chat.example.com/account/');
});

test('MAS auth-discovery key alone does not pull in the Meet-only experimental_features block', function (): void {
    $parsed = Yaml::parse(callingRenderer()->render(BASE_HOMESERVER, null, 'https://mas.chat.example.com/'));

    expect($parsed)->not->toHaveKey('experimental_features')
        ->and($parsed)->not->toHaveKey('rc_message')
        ->and($parsed['extra_well_known_client_content']['org.matrix.msc2965.authentication']['issuer'])
        ->toBe('https://mas.chat.example.com/')
        ->and($parsed['extra_well_known_client_content'])->not->toHaveKey('org.matrix.msc4143.rtc_foci');
});

test('unwiring Meet while MAS is active preserves the MAS discovery key', function (): void {
    // meet:unwire must never silently disable Element X's auth discovery —
    // the two concerns are independent and share one YAML key.
    $wired = callingRenderer()->render(BASE_HOMESERVER, 'https://meet.example.com/jwt', 'https://mas.chat.example.com/');
    $parsed = Yaml::parse(callingRenderer()->render($wired, null, 'https://mas.chat.example.com/'));

    expect($parsed)->not->toHaveKey('experimental_features')
        ->and($parsed['extra_well_known_client_content'])->not->toHaveKey('org.matrix.msc4143.rtc_foci')
        ->and($parsed['extra_well_known_client_content']['org.matrix.msc2965.authentication']['issuer'])
        ->toBe('https://mas.chat.example.com/');
});
