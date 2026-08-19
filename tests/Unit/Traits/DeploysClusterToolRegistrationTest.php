<?php

use App\Enums\ClusterTool;
use App\Traits\DeploysClusterTool;
use Illuminate\Support\Facades\Process;

/** Extract the JSON body a `saveToolRegistry()` write handed to `--from-file=registry.json=<tmpfile>`, before the trait unlinks it. */
function capturedRegistrationWrite(string $command): ?array
{
    if (! preg_match('/--from-file=registry\.json=(\S+)/', $command, $m)) {
        return null;
    }

    return json_decode(file_get_contents($m[1]), true);
}

test('registerDeployedTool merges $extra metadata alongside the host, e.g. adminEmail', function (): void {
    // data:init/sso:init/desk:init all pass adminEmail through this same
    // seam — a regression here silently drops it from the registry for
    // every one of them at once.
    $trait = new class
    {
        use DeploysClusterTool;

        public function register(string $kubectl, ClusterTool $tool, ?string $host, array $extra): bool
        {
            return $this->registerDeployedTool($tool, $kubectl, $host, extra: $extra);
        }
    };

    $captured = null;

    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(''),
        '*create namespace larakube-shared*' => Process::result(),
        '*create secret generic larakube-tools-registry*' => function ($process) use (&$captured) {
            $captured = capturedRegistrationWrite($process->command);

            return Process::result();
        },
    ]);

    expect($trait->register('kubectl', ClusterTool::SSO, 'sso.example.com', ['adminEmail' => 'admin@example.com']))->toBeTrue()
        ->and($captured)->toHaveCount(1)
        ->and($captured[0]['tool'])->toBe('sso')
        ->and($captured[0]['host'])->toBe('sso.example.com')
        ->and($captured[0]['adminEmail'])->toBe('admin@example.com');
});
