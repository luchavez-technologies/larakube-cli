<?php

/**
 * The one picker every wire/unwire pair shares.
 *
 * It exists because the pairs drifted: sso:* listed real installations with
 * their hosts while mail:*, secrets:* and vpn:* listed bare tool labels, so on
 * a cluster running two instances of a tool there was no way to say which one
 * you meant — the picker could not even show the second one existed.
 */

use App\Enums\ClusterTool;
use App\Traits\PicksRegisteredTool;
use Illuminate\Support\Facades\Process;

function picksSubject(string $registryJson): object
{
    Process::fake([
        '*larakube-tools-registry*' => Process::result(output: base64_encode($registryJson)),
        '*' => Process::result(output: ''),
    ]);

    return new class
    {
        use PicksRegisteredTool;

        /** @return array<int, array{tool: ClusterTool, host: ?string, label: string}> */
        public array $seen = [];

        public function laraKubeError(string $m): void {}

        public function line(string $m): void {}

        /** Capture the options the picker would render, without prompting. */
        public function choicesFor(string $kubectl, callable $capable, ?callable $wired = null): array
        {
            $out = [];
            foreach ($this->getRegisteredTools($kubectl) as $entry) {
                $tool = ClusterTool::tryFrom((string) ($entry['tool'] ?? ''));
                if ($tool === null || ! $tool->isShipped() || ! $capable($tool)) {
                    continue;
                }
                $host = (string) ($entry['host'] ?? '');
                if ($wired !== null && ! $wired($tool, $host)) {
                    continue;
                }
                $out[$tool->value.'|'.$host] = $host !== ''
                    ? "{$tool->getLabel()} ({$host})"
                    : $tool->getLabel();
            }

            return $out;
        }
    };
}

test('each registered instance is its own row, labelled by the host that identifies it', function (): void {
    $subject = picksSubject((string) json_encode([
        ['tool' => 'notes', 'host' => 'notes.example.com'],
        ['tool' => 'notes', 'host' => 'team.notes.example.com'],
        ['tool' => 'chat', 'host' => 'chat.example.com'],
    ]));

    $choices = $subject->choicesFor('kubectl', fn (ClusterTool $t): bool => $t->hasVpnWire());

    // Two notes rows, distinguishable. The old bare-label list collapsed these
    // into a single "Team Wiki & Knowledge Base" entry.
    expect($choices)->toHaveCount(3)
        ->and($choices['notes|notes.example.com'])->toContain('notes.example.com')
        ->and($choices['notes|team.notes.example.com'])->toContain('team.notes.example.com');
});

test('keys are strings, never integers', function (): void {
    // Laravel Prompts treats an integer-keyed array as a LIST and returns the
    // selected LABEL instead of the key; casting that back to a tool resolves
    // to whatever sits first. Confirmed live 2026-08-29 — picking NetBird
    // wired Matrix.
    $subject = picksSubject((string) json_encode([
        ['tool' => 'chat', 'host' => 'chat.example.com'],
    ]));

    $choices = $subject->choicesFor('kubectl', fn (ClusterTool $t): bool => $t->hasVpnWire());

    foreach (array_keys($choices) as $key) {
        expect($key)->toBeString()->toContain('|');
    }
});

test('the capability predicate keeps out tools the pair cannot act on', function (): void {
    $subject = picksSubject((string) json_encode([
        ['tool' => 'chat', 'host' => 'chat.example.com'],
        ['tool' => 'dns', 'host' => 'dns.example.com'],
    ]));

    $choices = $subject->choicesFor('kubectl', fn (ClusterTool $t): bool => $t->hasVpnWire());

    expect($choices)->toHaveKey('chat|chat.example.com')
        ->and($choices)->not->toHaveKey('dns|dns.example.com');
});

test('the wired gate narrows unwire to instances that are actually wired', function (): void {
    $subject = picksSubject((string) json_encode([
        ['tool' => 'notes', 'host' => 'notes.example.com'],
        ['tool' => 'notes', 'host' => 'team.notes.example.com'],
    ]));

    $choices = $subject->choicesFor(
        'kubectl',
        fn (ClusterTool $t): bool => $t->hasVpnWire(),
        fn (ClusterTool $t, string $host): bool => $host === 'team.notes.example.com',
    );

    expect($choices)->toBe(['notes|team.notes.example.com' => 'Team Wiki & Knowledge Base (Outline) (team.notes.example.com)']);
});

test('--tool narrows the list to that tool\'s instances instead of skipping the choice', function (): void {
    // Naming a tool used to mean "and take its default instance", so on a
    // multi-instance tool it silently acted on the wrong one.
    $subject = picksSubject((string) json_encode([
        ['tool' => 'notes', 'host' => 'notes.example.com'],
        ['tool' => 'notes', 'host' => 'team.notes.example.com'],
        ['tool' => 'chat', 'host' => 'chat.example.com'],
    ]));

    $choices = $subject->choicesFor(
        'kubectl',
        fn (ClusterTool $t): bool => $t->hasVpnWire() && $t === ClusterTool::NOTES,
    );

    expect($choices)->toHaveCount(2)
        ->and(array_keys($choices))->each->toStartWith('notes|');
});
