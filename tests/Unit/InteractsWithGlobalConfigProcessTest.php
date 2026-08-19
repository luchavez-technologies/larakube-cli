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

test('acmeEmailError rejects example.com/.net/.org — Let\'s Encrypt refuses their published Null MX record', function (): void {
    $helper = globalConfigEmailHelper();

    expect($helper->error('admin@example.com'))->not->toBeNull()
        ->and($helper->error('admin@example.net'))->not->toBeNull()
        ->and($helper->error('admin@example.org'))->not->toBeNull();
});

test('acmeEmailError accepts a real, deliverable address', function (): void {
    expect(globalConfigEmailHelper()->error('admin@gmail.com'))->toBeNull();
});

test('validStoredEmail discards a stored-but-undeliverable email, forcing a fresh prompt', function (): void {
    $helper = globalConfigEmailHelper();

    expect($helper->stored('admin@example.com'))->toBeNull()
        ->and($helper->stored(null))->toBeNull()
        ->and($helper->stored('admin@gmail.com'))->toBe('admin@gmail.com');
});

test('checkCaTrust on macOS reflects whether the CA is in the keychain', function (): void {
    Process::fake(['security find-certificate -c "Server Side Up CA"' => "keychain: ...\n"]);
    expect(globalConfigHelperOnDarwin()->caTrusted())->toBeTrue();

    Process::fake(['security find-certificate -c "Server Side Up CA"' => Process::result(output: '', exitCode: 1)]);
    expect(globalConfigHelperOnDarwin()->caTrusted())->toBeFalse();
});
