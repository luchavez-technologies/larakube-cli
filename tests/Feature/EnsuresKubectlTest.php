<?php

use App\Traits\EnsuresKubectl;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

function kubectlPreflightRunner(bool $interactive): Command
{
    $command = new class extends Command
    {
        use EnsuresKubectl;

        protected $signature = 'test:ensure-kubectl';

        public function bind(bool $interactive): void
        {
            $this->input = new ArrayInput([]);
            $this->input->setInteractive($interactive);
            $this->output = new OutputStyle($this->input, new BufferedOutput);
        }

        public function check(): bool
        {
            return $this->ensureKubectl();
        }
    };

    $command->bind($interactive);

    return $command;
}

test('an installed kubectl passes the preflight without touching the installer', function (): void {
    Process::fake([
        'command -v kubectl' => Process::result('/usr/local/bin/kubectl'),
        '*' => Process::result('', exitCode: 1),
    ]);

    expect(kubectlPreflightRunner(interactive: true)->check())->toBeTrue();

    // No install attempt of any shape — brew, curl or otherwise.
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'dl.k8s.io')
        || str_contains($process->command, 'brew install kubernetes-cli'));
});

test('a non-interactive run refuses instead of prompting', function (): void {
    // This guard runs BEFORE tofu provisions anything, so refusing here costs
    // nothing; refusing after would leave a paid-for cluster nobody can reach.
    Process::fake([
        'command -v kubectl' => Process::result('', exitCode: 1),
        '*' => Process::result('', exitCode: 1),
    ]);

    expect(kubectlPreflightRunner(interactive: false)->check())->toBeFalse();

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'dl.k8s.io'));
});
