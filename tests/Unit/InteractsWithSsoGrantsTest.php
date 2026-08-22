<?php

/**
 * Unit coverage for resolveInstanceForTool()'s tri-state contract, exercised
 * indirectly via SsoGrantCommandTest/SsoRevokeCommandTest/SsoOrgGrantCommandTest
 * but worth pinning down directly once here since all three commands share it.
 */

use App\Enums\ClusterTool;
use App\Traits\InteractsWithSsoGrants;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Facades\Process;

function ssoGrantsInstanceResolver(): object
{
    return new class
    {
        use InteractsWithSsoGrants, LaraKubeOutput;

        public function resolve(ClusterTool $tool, string $kubectl, string $domainOption): string|false|null
        {
            return $this->resolveInstanceForTool($tool, $kubectl, $domainOption);
        }
    };
}

function ssoGrantsRegistryFake(array $entries): void
{
    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(output: base64_encode((string) json_encode($entries))),
    ]);
}

test('resolveInstanceForTool returns null for a single-instance tool with no --domain=', function (): void {
    expect(ssoGrantsInstanceResolver()->resolve(ClusterTool::SECRETS, 'kubectl', ''))->toBeNull();
});

test('resolveInstanceForTool honours --domain= outright, even for a single-instance tool', function (): void {
    ssoGrantsRegistryFake([
        ['tool' => 'secrets', 'instance' => 'main', 'installedAt' => '2026-08-01T00:00:00+00:00', 'host' => 'secrets.luchtech.dev'],
    ]);

    expect(ssoGrantsInstanceResolver()->resolve(ClusterTool::SECRETS, 'kubectl', 'secrets.luchtech.dev'))->toBe('main');
});

test('resolveInstanceForTool returns null for a multi-instance tool with no registered instances yet', function (): void {
    ssoGrantsRegistryFake([]);

    expect(ssoGrantsInstanceResolver()->resolve(ClusterTool::NOTES, 'kubectl', ''))->toBeNull();
});

test('resolveInstanceForTool auto-picks the one named instance of a multi-instance tool', function (): void {
    ssoGrantsRegistryFake([
        ['tool' => 'notes', 'instance' => 'notes-luchtech-dev', 'installedAt' => '2026-08-01T00:00:00+00:00', 'host' => 'notes.luchtech.dev'],
    ]);

    expect(ssoGrantsInstanceResolver()->resolve(ClusterTool::NOTES, 'kubectl', ''))->toBe('notes-luchtech-dev');
});

test('resolveInstanceForTool refuses to guess when 2+ named instances exist and no --domain= is given', function (): void {
    ssoGrantsRegistryFake([
        ['tool' => 'notes', 'instance' => 'notes-luchtech-dev', 'installedAt' => '2026-08-01T00:00:00+00:00', 'host' => 'notes.luchtech.dev'],
        ['tool' => 'notes', 'instance' => 'blog-example-com', 'installedAt' => '2026-08-02T00:00:00+00:00', 'host' => 'blog.example.com'],
    ]);

    expect(ssoGrantsInstanceResolver()->resolve(ClusterTool::NOTES, 'kubectl', ''))->toBeFalse();
});
