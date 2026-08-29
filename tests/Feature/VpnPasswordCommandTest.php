<?php

/**
 * vpn:password resets the EMBEDDED IdP user's password — the credential the
 * NetBird dashboard logs in with, since the dashboard authenticates against
 * embedded Dex rather than the external IdP.
 *
 * Its reason for existing is keeping two things in step: the password inside
 * Dex, and the copy vpn:init stores in vpn-management-secrets and prints. Changing only
 * the first (a hand-rolled `netbird-mgmt admin user change-password`) leaves
 * the stored copy stale, and the next vpn:init prints a password that does not
 * work.
 */

use Illuminate\Support\Facades\Process;

function vpnPasswordKubectl(): string
{
    return 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';
}

/** @return array<string, mixed> */
function vpnPasswordFakes(string $kubectl, bool $installed = true, string $adminEmail = 'admin@vpn.example.com'): array
{
    return [
        "{$kubectl} get deployment vpn-management -n larakube-vpn*" => $installed
            ? Process::result(output: 'vpn-management 1/1 1 1 3d')
            : Process::result(output: '', exitCode: 1),
        '*data.admin-email*' => Process::result(output: base64_encode($adminEmail)),
        '*change-password*' => Process::result(output: 'password updated'),
        '*patch secret vpn-management-secrets*' => Process::result(output: 'secret/vpn-management-secrets patched'),
    ];
}

test('vpn:password changes the password in the embedded IdP and records it in vpn-management-secrets', function (): void {
    $kubectl = vpnPasswordKubectl();
    Process::fake(vpnPasswordFakes($kubectl));

    $this->artisan('vpn:password local --force --password=hunter2-hunter2')
        ->assertExitCode(0)
        ->expectsOutputToContain('Dashboard password updated');

    Process::assertRan(fn ($process) => str_contains($process->command, 'netbird-mgmt admin user change-password')
        && str_contains($process->command, '--email '));

    // Both halves, or the stored copy silently rots.
    Process::assertRan(fn ($process) => str_contains($process->command, 'patch secret vpn-management-secrets')
        && str_contains($process->command, 'admin-password'));
});

test('vpn:password never puts the new password in the container process list', function (): void {
    // --password-file - reads stdin. The --password flag would expose the
    // credential to anything able to read /proc inside that pod.
    $kubectl = vpnPasswordKubectl();
    Process::fake(vpnPasswordFakes($kubectl));

    $this->artisan('vpn:password local --force --password=hunter2-hunter2')->assertExitCode(0);

    Process::assertRan(fn ($process) => str_contains($process->command, 'change-password')
        && str_contains($process->command, '--password-file -')
        && ! str_contains($process->command, '--password hunter2-hunter2'));
});

test('vpn:password defaults to the account vpn:init created', function (): void {
    $kubectl = vpnPasswordKubectl();
    Process::fake(vpnPasswordFakes($kubectl, adminEmail: 'owner@example.com'));

    $this->artisan('vpn:password local --force')->assertExitCode(0);

    Process::assertRan(fn ($process) => str_contains($process->command, 'change-password')
        && str_contains($process->command, 'owner@example.com'));
});

test('vpn:password fails clearly when NetBird is not installed', function (): void {
    $kubectl = vpnPasswordKubectl();
    Process::fake(vpnPasswordFakes($kubectl, installed: false));

    $this->artisan('vpn:password local --force')
        ->assertExitCode(1)
        ->expectsOutputToContain('NetBird is not installed');
});

test('vpn:password warns rather than lying when the secret cannot be updated', function (): void {
    // The password IS changed by this point, so failing the command outright
    // would misrepresent what happened — the stored copy is just stale.
    $kubectl = vpnPasswordKubectl();
    $fakes = vpnPasswordFakes($kubectl);
    $fakes['*patch secret vpn-management-secrets*'] = Process::result(output: 'denied', exitCode: 1);
    Process::fake($fakes);

    $this->artisan('vpn:password local --force --password=hunter2-hunter2')
        ->assertExitCode(0)
        ->expectsOutputToContain('stored copy is now stale');
});
