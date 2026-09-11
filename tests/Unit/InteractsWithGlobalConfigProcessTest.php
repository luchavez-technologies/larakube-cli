<?php

/**
 * Tests for InteractsWithGlobalConfig's Process-backed checks. getGhCommand()
 * also requires the candidate to be a real, executable file on disk
 * (@is_executable()) — same caveat as ConfiguresCloudEnvironment's
 * resolveGlabCommand(), so the "found via command -v" case needs an actual
 * temporary executable to resolve to.
 */

use App\Traits\InteractsWithGlobalConfig;
use App\Traits\InteractsWithOs;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Validator;
use Spatie\TemporaryDirectory\TemporaryDirectory;

function globalConfigHelper(): object
{
    return new class
    {
        use InteractsWithGlobalConfig, InteractsWithOs;

        public function gh(): string
        {
            return $this->getGhCommand();
        }
    };
}

function globalConfigEmailHelper(): object
{
    return new class
    {
        use InteractsWithGlobalConfig, InteractsWithOs;

        public function error(string $email): ?string
        {
            return $this->acmeEmailError($email);
        }

        public function stored(?string $email): ?string
        {
            return $this->validStoredEmail($email);
        }
    };
}

function globalConfigHelperOnDarwin(): object
{
    return new class
    {
        use InteractsWithGlobalConfig, InteractsWithOs;

        public function caTrusted(): bool
        {
            return $this->checkCaTrust();
        }

        protected function isDarwin(): bool
        {
            return true;
        }
    };
}

test('getGhCommand prefers a real command -v hit over the docker fallback', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $fakeGh = $temporaryDirectory->path().'/fake-gh';
    file_put_contents($fakeGh, "#!/bin/sh\necho fake-gh\n");
    chmod($fakeGh, 0755);

    try {
        Process::fake(['command -v gh' => $fakeGh."\n"]);

        expect(globalConfigHelper()->gh())->toBe($fakeGh);
    } finally {
        $temporaryDirectory->delete();
    }
});

test('getGhCommand falls back to the dockerized gh when nothing resolves to a real executable', function (): void {
    Process::fake(['command -v gh' => Process::result(output: '', exitCode: 1)]);

    if (collect(['/usr/local/bin/gh', '/opt/homebrew/bin/gh', '/home/linuxbrew/.linuxbrew/bin/gh'])->contains(fn ($p) => @is_executable($p))) {
        $this->markTestSkipped('gh is actually installed at a fallback path on this machine.');
    }

    expect(globalConfigHelper()->gh())->toContain('docker run');
});

test('acmeEmailError rejects syntactically invalid input without touching the network', function (): void {
    expect(globalConfigEmailHelper()->error('not-an-email'))->not->toBeNull();
});

test('acmeEmailError accepts a deliverable address', function (): void {
    // TestCase::setUp() calls Validator::fakeDnsLookups(), so the domain is not
    // actually resolved — this asserts our own plumbing (rule wired up, message
    // surfaced), not what gmail.com's nameservers say today.
    expect(globalConfigEmailHelper()->error('admin@gmail.com'))->toBeNull();
});

test('validStoredEmail passes a usable address through and discards nothing else', function (): void {
    $helper = globalConfigEmailHelper();

    expect($helper->stored(null))->toBeNull()
        ->and($helper->stored(''))->toBeNull()
        ->and($helper->stored('not-an-email'))->toBeNull()
        ->and($helper->stored('admin@gmail.com'))->toBe('admin@gmail.com');
});

/**
 * Needs a live resolver, so it is opt-in (`--group=network`) and skips when the
 * resolver cannot see example.com's Null MX record.
 */
test('acmeEmailError rejects Null MX domains against a real resolver', function (): void {
    Validator::fakeDnsLookups(false);

    $records = @dns_get_record('example.com', DNS_MX);
    $publishesNullMx = is_array($records) && $records !== [] && array_reduce(
        $records,
        fn (bool $carry, array $r): bool => $carry && rtrim((string) ($r['target'] ?? ''), '.') === '',
        true,
    );

    if (! $publishesNullMx) {
        Validator::fakeDnsLookups();
        test()->markTestSkipped('resolver did not return example.com\'s Null MX — cannot assert it here');
    }

    $helper = globalConfigEmailHelper();

    try {
        expect($helper->error('admin@example.com'))->not->toBeNull()
            ->and($helper->stored('admin@example.com'))->toBeNull();
    } finally {
        Validator::fakeDnsLookups();
    }
})->group('network');

test('checkCaTrust on macOS reflects whether the CA is in the keychain', function (): void {
    Process::fake(['security find-certificate -c "Server Side Up CA"' => "keychain: ...\n"]);
    expect(globalConfigHelperOnDarwin()->caTrusted())->toBeTrue();

    Process::fake(['security find-certificate -c "Server Side Up CA"' => Process::result(output: '', exitCode: 1)]);
    expect(globalConfigHelperOnDarwin()->caTrusted())->toBeFalse();
});
