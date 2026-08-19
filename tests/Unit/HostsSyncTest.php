<?php

/**
 * Unit tests for the host-sync logic that's pure/decidable. The elevated
 * Windows write (PowerShell + UAC) and the actual /etc/hosts write need a real
 * machine, so they aren't unit-tested — but the two things that can go subtly
 * wrong are: (1) the idempotent block-building (duplicate/garbled entries) and
 * (2) WSL detection (whether the Windows sync even runs). Those are covered here.
 */

use App\Traits\InteractsWithHosts;

function hostsHelper(): object
{
    return new class
    {
        use InteractsWithHosts;

        public function apply(string $current, string $id, string $entry): string
        {
            return $this->applyHostsBlock($current, $id, $entry);
        }

        public function wsl(): bool
        {
            return $this->isWsl();
        }
    };
}

test('applyHostsBlock appends a block and preserves existing content', function (): void {
    $result = hostsHelper()->apply("127.0.0.1 localhost\n", '# LaraKube: app', '127.0.0.1 app.test');

    expect($result)->toContain('127.0.0.1 localhost')      // existing entries kept
        ->and($result)->toContain('# LaraKube: app')
        ->and($result)->toContain('127.0.0.1 app.test');
});

test('applyHostsBlock is idempotent (no duplicate blocks on repeat)', function (): void {
    $h = hostsHelper();
    $once = $h->apply("127.0.0.1 localhost\n", '# LaraKube: app', '127.0.0.1 app.test');
    $twice = $h->apply($once, '# LaraKube: app', '127.0.0.1 app.test');

    expect($twice)->toBe($once)
        ->and(substr_count($twice, '# LaraKube: app'))->toBe(1);
});

test('applyHostsBlock replaces an existing block in place (updates, no dupes)', function (): void {
    $h = hostsHelper();
    $first = $h->apply("127.0.0.1 localhost\n", '# LaraKube: app', '127.0.0.1 old.test');
    $second = $h->apply($first, '# LaraKube: app', '127.0.0.1 new.test ws.new.test');

    expect(substr_count($second, '# LaraKube: app'))->toBe(1) // single block
        ->and($second)->toContain('new.test')
        ->and($second)->not->toContain('old.test')           // stale entry gone
        ->and($second)->toContain('127.0.0.1 localhost');    // unrelated entries kept
});

test('isWsl detects the WSL_DISTRO_NAME environment variable', function (): void {
    $original = getenv('WSL_DISTRO_NAME');

    try {
        putenv('WSL_DISTRO_NAME=Ubuntu');
        expect(hostsHelper()->wsl())->toBeTrue();
    } finally {
        $original === false ? putenv('WSL_DISTRO_NAME') : putenv("WSL_DISTRO_NAME={$original}");
    }
});
