<?php

use App\Services\ReleaseVersionCalculator;

test('calculates minor bump for feat in pre-v1', function (): void {
    $calculator = new ReleaseVersionCalculator;

    $commits = [
        [
            'hash' => 'abc1234567',
            'subject' => 'feat(cluster): add automated backup pruning',
            'body' => '',
        ],
    ];

    $result = $calculator->calculate('v0.34.0', $commits, 'luchaveztech/larakube-cli');

    expect($result['should_release'])->toBeTrue()
        ->and($result['current_version'])->toBe('v0.34.0')
        ->and($result['next_version'])->toBe('v0.35.0')
        ->and($result['bump_type'])->toBe('minor')
        ->and($result['release_notes'])->toContain('### 🚀 Features')
        ->and($result['release_notes'])->toContain('**cluster:** add automated backup pruning');
});

test('calculates patch bump for fix in pre-v1', function (): void {
    $calculator = new ReleaseVersionCalculator;

    $commits = [
        [
            'hash' => 'abc1234567',
            'subject' => 'fix(dns): resolve timeout in coredns lookups',
            'body' => '',
        ],
    ];

    $result = $calculator->calculate('v0.34.0', $commits);

    expect($result['should_release'])->toBeTrue()
        ->and($result['next_version'])->toBe('v0.34.1')
        ->and($result['bump_type'])->toBe('patch')
        ->and($result['release_notes'])->toContain('### 🐛 Bug Fixes & Improvements');
});

test('breaking change in pre-v1 bumps minor not major', function (): void {
    $calculator = new ReleaseVersionCalculator;

    $commits = [
        [
            'hash' => 'abc1234567',
            'subject' => 'feat(data)!: remove legacy data:init command',
            'body' => 'BREAKING CHANGE: The data:init command has been removed.',
        ],
    ];

    $result = $calculator->calculate('v0.34.0', $commits);

    expect($result['should_release'])->toBeTrue()
        ->and($result['next_version'])->toBe('v0.35.0')
        ->and($result['bump_type'])->toBe('minor')
        ->and($result['release_notes'])->toContain('### ⚠️ Breaking Changes');
});

test('Release-As override forces target version', function (): void {
    $calculator = new ReleaseVersionCalculator;

    $commits = [
        [
            'hash' => 'abc1234567',
            'subject' => 'feat: graduate larakube to v1.0.0',
            'body' => "Official stable release.\n\nRelease-As: 1.0.0",
        ],
    ];

    $result = $calculator->calculate('v0.34.0', $commits);

    expect($result['should_release'])->toBeTrue()
        ->and($result['next_version'])->toBe('v1.0.0')
        ->and($result['bump_type'])->toBe('explicit');
});

test('post-v1 breaking change bumps major', function (): void {
    $calculator = new ReleaseVersionCalculator;

    $commits = [
        [
            'hash' => 'abc1234567',
            'subject' => 'feat!: complete rework of CLI blueprint schema',
            'body' => 'BREAKING CHANGE: Old blueprint schema is no longer compatible.',
        ],
    ];

    $result = $calculator->calculate('v1.4.2', $commits);

    expect($result['should_release'])->toBeTrue()
        ->and($result['next_version'])->toBe('v2.0.0')
        ->and($result['bump_type'])->toBe('major');
});

test('post-v1 feat bumps minor and fix bumps patch', function (): void {
    $calculator = new ReleaseVersionCalculator;

    $featResult = $calculator->calculate('v1.2.3', [
        ['hash' => '111', 'subject' => 'feat: add new provider', 'body' => ''],
    ]);
    expect($featResult['next_version'])->toBe('v1.3.0');

    $fixResult = $calculator->calculate('v1.2.3', [
        ['hash' => '222', 'subject' => 'fix: fix broken flag', 'body' => ''],
    ]);
    expect($fixResult['next_version'])->toBe('v1.2.4');
});

test('does not trigger release when only non-releasing commits exist', function (): void {
    $calculator = new ReleaseVersionCalculator;

    $commits = [
        ['hash' => '111', 'subject' => 'chore: bump dependencies', 'body' => ''],
        ['hash' => '222', 'subject' => 'docs(adr): add ADR 0025', 'body' => ''],
        ['hash' => '333', 'subject' => 'test: add test coverage', 'body' => ''],
        ['hash' => '444', 'subject' => 'refactor: simplify internal resolver', 'body' => ''],
        ['hash' => '555', 'subject' => 'ci: update workflow action', 'body' => ''],
    ];

    $result = $calculator->calculate('v0.34.0', $commits);

    expect($result['should_release'])->toBeFalse()
        ->and($result['next_version'])->toBeNull();
});

test('does not trigger release for empty commits', function (): void {
    $calculator = new ReleaseVersionCalculator;

    $result = $calculator->calculate('v0.34.0', []);

    expect($result['should_release'])->toBeFalse()
        ->and($result['next_version'])->toBeNull();
});
