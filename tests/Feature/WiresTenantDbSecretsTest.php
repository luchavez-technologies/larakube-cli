<?php

use App\Traits\InteractsWithSecrets;
use App\Traits\SyncsClusterSecrets;
use App\Traits\WiresTenantDbSecrets;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

function makeWiresTenantDbSecretsHarness(): object
{
    $command = new class extends Command
    {
        use InteractsWithSecrets;
        use SyncsClusterSecrets;
        use WiresTenantDbSecrets;

        protected $signature = 'test:wire-tenant-db';

        public function runWire(
            string $kubectl,
            string $namespace,
            string $roleName,
            string $secretName = 'laravel-secrets',
            string $passwordKey = 'DB_PASSWORD',
        ): bool {
            return $this->wireTenantDbSecret($kubectl, $namespace, $roleName, $secretName, $passwordKey);
        }
    };

    $input = new ArrayInput([]);
    $input->bind($command->getDefinition());
    $command->setInput($input);
    $command->setOutput(new OutputStyle($input, new BufferedOutput));

    return $command;
}

test('WiresTenantDbSecrets creates an empty target Secret when absent before applying eso-db-static', function (): void {
    openBaoRegistered();
    $cwd = getcwd();
    chdir(sys_get_temp_dir());

    $events = [];
    $refreshReads = 0;

    try {
        Process::fake(function ($process) use (&$events, &$refreshReads) {
            $cmd = $process->command;

            if (str_contains($cmd, 'get secret ')) {
                $events[] = 'get-secret';

                return Process::result(output: '');
            }

            if (str_contains($cmd, 'create secret generic') || str_contains($cmd, 'apply -f -')) {
                $events[] = 'create-secret';

                return Process::result(output: 'secret/laravel-secrets created');
            }

            if (str_contains($cmd, 'apply -f ')) {
                $events[] = 'apply-eso';

                return Process::result(output: 'externalsecret/laravel-secrets-db configured');
            }

            if (str_contains($cmd, 'refreshTime')) {
                $refreshReads++;

                return Process::result(output: $refreshReads === 1 ? '' : '2026-08-01T00:00:00Z');
            }

            if (str_contains($cmd, '].status}')) {
                return Process::result(output: 'True');
            }

            if (str_contains($cmd, '].reason}')) {
                return Process::result(output: 'SecretSynced');
            }

            if (str_contains($cmd, 'get deployment web')) {
                return Process::result(output: '', exitCode: 1);
            }

            return Process::result();
        });

        expect(makeWiresTenantDbSecretsHarness()->runWire('kubectl', 'tenant-a-production', 'tenant-tenant_a'))->toBeTrue()
            ->and($events)->toBe(['get-secret', 'create-secret', 'apply-eso']);
    } finally {
        if ($cwd !== false) {
            chdir($cwd);
        }
    }
});

test('WiresTenantDbSecrets skips empty Secret creation when the target Secret already exists', function (): void {
    openBaoRegistered();

    Process::fake([
        '*get secret *' => Process::result(output: 'secret/laravel-secrets'),
        '*apply -f *' => Process::result(output: 'externalsecret/laravel-secrets-db configured'),
        '*].status}*' => Process::result(output: 'True'),
        '*].reason}*' => Process::result(output: 'SecretSynced'),
        '*refreshTime}*' => Process::sequence([
            Process::result(output: ''),
            Process::result(output: '2026-08-01T00:00:01Z'),
        ]),
        '*get deployment web*' => Process::result(output: '', exitCode: 1),
        '*' => Process::result(),
    ]);

    expect(makeWiresTenantDbSecretsHarness()->runWire('kubectl', 'tenant-b-production', 'tenant-tenant_b'))->toBeTrue();

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'create secret generic') || str_contains($process->command, 'apply -f -'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'apply -f ') && ! str_contains($process->command, 'apply -f -'));
});
