<?php

use Symfony\Component\Yaml\Yaml;

function callingRenderer(): object
{
    return new class
    {
        use App\Traits\InteractsWithChat;

        public function render(string $yaml, ?string $url): string
        {
            return $this->renderSynapseCalling($yaml, $url);
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

test('an inert well_known block left by an older render is stripped, not preserved', function () {
    // Older templates wrote `well_known:`, which Synapse ignores entirely.
    // Leaving it behind next to the real key is a trap for the next reader.
    $legacy = BASE_HOMESERVER."well_known:\n  client:\n    \"org.matrix.msc4143.rtc_foci\": []\n";

    $parsed = Yaml::parse(callingRenderer()->render($legacy, 'https://meet.example.com/jwt'));

    expect($parsed)->not->toHaveKey('well_known')
        ->and($parsed['extra_well_known_client_content']['org.matrix.msc4143.rtc_foci'][0]['livekit_service_url'])
        ->toBe('https://meet.example.com/jwt');
});

test('wiring writes the whole calling block, not just the focus URL', function () {
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

test('unwiring strips every part of the calling block', function () {
    $wired = callingRenderer()->render(BASE_HOMESERVER, 'https://meet.example.com/jwt');
    $parsed = Yaml::parse(callingRenderer()->render($wired, null));

    expect($parsed)->not->toHaveKey('experimental_features')
        ->and($parsed)->not->toHaveKey('max_event_delay_duration')
        ->and($parsed)->not->toHaveKey('rc_message')
        ->and($parsed)->not->toHaveKey('rc_delayed_event_mgmt')
        ->and($parsed)->not->toHaveKey('well_known')
        ->and($parsed)->not->toHaveKey('extra_well_known_client_content');
});

test('rewriting the calling block preserves unrelated config', function () {
    // meet:wire edits the live homeserver.yaml in place; clobbering the mail or
    // database blocks would take Synapse down rather than just calling.
    $parsed = Yaml::parse(callingRenderer()->render(BASE_HOMESERVER, 'https://meet.example.com/jwt'));

    expect($parsed['server_name'])->toBe('chat.example.com')
        ->and($parsed['report_stats'])->toBeFalse()
        ->and($parsed['email']['notif_from'])->toBe('noreply@example.com');
});

test('re-wiring is idempotent — the block is replaced, never duplicated', function () {
    $once = callingRenderer()->render(BASE_HOMESERVER, 'https://meet.example.com/jwt');
    $twice = callingRenderer()->render($once, 'https://meet.example.com/jwt');

    expect($twice)->toBe($once)
        ->and(substr_count($twice, 'extra_well_known_client_content:'))->toBe(1);
});

test('re-wiring to a different Meet host replaces the old focus', function () {
    $first = callingRenderer()->render(BASE_HOMESERVER, 'https://meet.example.com/jwt');
    $parsed = Yaml::parse(callingRenderer()->render($first, 'https://meet.other.com/jwt'));

    $foci = $parsed['extra_well_known_client_content']['org.matrix.msc4143.rtc_foci'];

    expect($foci)->toHaveCount(1)
        ->and($foci[0]['livekit_service_url'])->toBe('https://meet.other.com/jwt');
});
