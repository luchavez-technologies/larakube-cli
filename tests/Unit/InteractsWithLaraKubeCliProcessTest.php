<?php

use App\Traits\InteractsWithLaraKubeCli;
use Illuminate\Support\Facades\Process;

function laraKubeCliHelper(?string $bin = 'larakube'): object
{
    return new class($bin)
    {
        use InteractsWithLaraKubeCli;

        public function __construct(private ?string $bin) {}

        public function list(): string
        {
            return $this->listCliCommands();
        }

        public function help(string $command): string
        {
            return $this->getCliCommandHelp($command);
        }

        public function execute(string $command): array
        {
            return $this->executeCliCommand($command);
        }

        protected function getLaraKubeBinary(): string
        {
            return $this->bin;
        }
    };
}

test('listCliCommands returns the raw command list output', function () {
    Process::fake(['larakube list --raw' => "up\ndown\ninit\n"]);

    expect(laraKubeCliHelper()->list())->toBe("up\ndown\ninit\n");
});

test('getCliCommandHelp returns the help text when present', function () {
    Process::fake(['larakube help up' => 'Usage: larakube up'.PHP_EOL]);

    expect(laraKubeCliHelper()->help('up'))->toBe('Usage: larakube up'.PHP_EOL);
});

test('getCliCommandHelp falls back to a friendly message when there is no output', function () {
    Process::fake(['larakube help bogus' => '']);

    expect(laraKubeCliHelper()->help('bogus'))->toBe('No help found for command: bogus');
});

test('executeCliCommand strips a redundant larakube prefix and adds --no-interaction', function () {
    Process::fake(['larakube up --no-interaction' => Process::result(output: 'up ok', exitCode: 0)]);

    $result = laraKubeCliHelper()->execute('larakube up');

    expect($result['command'])->toBe('larakube up --no-interaction')
        ->and($result['output'])->toBe('up ok')
        ->and($result['exit_code'])->toBe(0)
        ->and($result['success'])->toBeTrue();
});

test('executeCliCommand forces --force onto a down command', function () {
    Process::fake(['larakube down --no-interaction --force' => Process::result(exitCode: 0)]);

    $result = laraKubeCliHelper()->execute('down');

    expect($result['command'])->toBe('larakube down --no-interaction --force');
});

test('executeCliCommand reports failure with combined stdout/stderr on a non-zero exit', function () {
    Process::fake(['larakube heal --no-interaction' => Process::result(output: 'partial output', errorOutput: 'boom', exitCode: 1)]);

    $result = laraKubeCliHelper()->execute('heal');

    expect($result['success'])->toBeFalse()
        ->and($result['exit_code'])->toBe(1)
        ->and($result['output'])->toContain('partial output')
        ->and($result['output'])->toContain('boom');
});
