<?php

/**
 * dockerGroupNeedsRefresh() shells out to `getent`/`id` rather than the posix
 * extension (not compiled into the standalone binary — see hostUid()).
 * Migrated to the Process facade, so this fakes per-command output via
 * Process::fake() rather than the shared shell_exec() namespace-override mock.
 */

use App\Traits\InteractsWithDocker;
use Illuminate\Support\Facades\Process;

function dockerGroupHarness(): object
{
    return new class
    {
        use InteractsWithDocker;

        public function check(): bool
        {
            return $this->dockerGroupNeedsRefresh();
        }
    };
}

/**
 * @param  array<string, string|null>  $responses  keys: 'getent', 'un', 'gn', 'Gn'
 */
function mockDockerGroupCommands(array $responses): void
{
    Process::fake([
        'getent group docker' => $responses['getent'] ?? Process::result(output: '', exitCode: 1),
        'id -un' => $responses['un'] ?? Process::result(output: '', exitCode: 1),
        'id -gn' => $responses['gn'] ?? Process::result(output: '', exitCode: 1),
        'id -Gn' => $responses['Gn'] ?? Process::result(output: '', exitCode: 1),
    ]);
}

test('dockerGroupNeedsRefresh is true when the user is a member but docker is missing from the active session', function (): void {
    mockDockerGroupCommands([
        'getent' => "docker:x:989:james\n",
        'un' => "james\n",
        'gn' => "james\n",
        'Gn' => "james adm sudo\n", // docker missing here — stale session
    ]);

    expect(dockerGroupHarness()->check())->toBeTrue();
});

test('dockerGroupNeedsRefresh is false when docker is already active in this session', function (): void {
    mockDockerGroupCommands([
        'getent' => "docker:x:989:james\n",
        'un' => "james\n",
        'gn' => "james\n",
        'Gn' => "james adm sudo docker\n",
    ]);

    expect(dockerGroupHarness()->check())->toBeFalse();
});

test('dockerGroupNeedsRefresh is true via primary group membership even when not listed as a secondary member', function (): void {
    mockDockerGroupCommands([
        'getent' => "docker:x:989:\n", // no secondary members listed
        'un' => "james\n",
        'gn' => "docker\n", // but docker IS this user's primary group
        'Gn' => "james\n",
    ]);

    expect(dockerGroupHarness()->check())->toBeTrue();
});

test('dockerGroupNeedsRefresh is false when the user is not a docker group member at all', function (): void {
    mockDockerGroupCommands([
        'getent' => "docker:x:989:someoneelse\n",
        'un' => "james\n",
        'gn' => "james\n",
        'Gn' => "james adm sudo\n",
    ]);

    expect(dockerGroupHarness()->check())->toBeFalse();
});

test('dockerGroupNeedsRefresh is false when there is no docker group on this system', function (): void {
    mockDockerGroupCommands([
        'getent' => null,
        'un' => "james\n",
        'gn' => "james\n",
        'Gn' => "james adm sudo\n",
    ]);

    expect(dockerGroupHarness()->check())->toBeFalse();
});

test('dockerGroupNeedsRefresh is false when id -un fails to resolve a user', function (): void {
    mockDockerGroupCommands([
        'getent' => "docker:x:989:james\n",
        'un' => null,
        'gn' => "james\n",
        'Gn' => "james adm sudo\n",
    ]);

    expect(dockerGroupHarness()->check())->toBeFalse();
});
