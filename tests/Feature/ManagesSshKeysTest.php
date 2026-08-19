<?php

/**
 * ManagesSshKeys: key generation for users with no SSH key yet, and the
 * ~/.ssh/config upsert that makes `ssh <stack-name>` work after cloud:create.
 * HOME points at the test temp dir (TestCase), so the real ~/.ssh is never
 * touched. Generation runs the real ssh-keygen (present on every dev/CI
 * machine this repo targets) — faking it would just test the fake.
 */

use App\Traits\LaraKubeOutput;
use App\Traits\ManagesSshKeys;
use Illuminate\Support\Facades\Process;

function sshKeysHelper(): object
{
    return new class
    {
        use LaraKubeOutput, ManagesSshKeys;

        public function generate(string $keyPath): bool
        {
            return $this->generateSshKey($keyPath);
        }

        public function upsert(string $host, string $hostName, string $user, string $port, string $identityFile): void
        {
            $this->upsertSshConfigHost($host, $hostName, $user, $port, $identityFile);
        }
    };
}

test('generateSshKey mints a usable ED25519 key pair', function (): void {
    $keyPath = home_path('.ssh/larakube_test_'.uniqid());

    expect(sshKeysHelper()->generate($keyPath))->toBeTrue()
        ->and(file_exists($keyPath))->toBeTrue()
        ->and(file_get_contents($keyPath.'.pub'))->toStartWith('ssh-ed25519 ');
})->skip(fn () => trim((string) shell_exec('command -v ssh-keygen')) === '', 'ssh-keygen is not available in this test environment');

test('generateSshKey reports failure when ssh-keygen fails', function (): void {
    Process::fake(['ssh-keygen *' => Process::result(output: '', exitCode: 1)]);

    expect(sshKeysHelper()->generate(home_path('.ssh/never_created')))->toBeFalse()
        ->and(file_exists(home_path('.ssh/never_created')))->toBeFalse();
});

test('upsertSshConfigHost appends a Host block and preserves existing entries', function (): void {
    @mkdir(home_path('.ssh'), 0700, true);
    file_put_contents(home_path('.ssh/config'), "Host work\n    HostName work.example.com\n");

    sshKeysHelper()->upsert('myapp-vps', '203.0.113.7', 'larakube', '22', home_path('.ssh/id_rsa'));

    $config = file_get_contents(home_path('.ssh/config'));
    expect($config)->toContain("Host work\n    HostName work.example.com")
        ->and($config)->toContain('Host myapp-vps')
        ->and($config)->toContain('HostName 203.0.113.7')
        ->and($config)->toContain('User larakube')
        ->and($config)->toContain('IdentityFile '.home_path('.ssh/id_rsa'));
});

test('upsertSshConfigHost replaces an existing block for the same alias (re-provision updates the IP)', function (): void {
    @mkdir(home_path('.ssh'), 0700, true);
    @unlink(home_path('.ssh/config'));

    $helper = sshKeysHelper();
    $helper->upsert('myapp-vps', '203.0.113.7', 'larakube', '22', home_path('.ssh/id_rsa'));
    $helper->upsert('myapp-vps', '198.51.100.9', 'larakube', '22', home_path('.ssh/id_rsa'));

    $config = file_get_contents(home_path('.ssh/config'));
    expect(substr_count($config, 'Host myapp-vps'))->toBe(1)
        ->and($config)->toContain('HostName 198.51.100.9')
        ->and($config)->not->toContain('203.0.113.7');
});
