<?php

use App\Commands\Cloud\CloudCreateCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputOption;

/**
 * A command that records the commands connectCloudflare() calls, so the
 * decision (ask, opt in by flag, or skip) is tested without a server.
 */
function cloudflareProbe(array $input): object
{
    $probe = new class extends CloudCreateCommand
    {
        /** @var list<array{0: string, 1: array<string, mixed>}> */
        public array $called = [];

        public ?Throwable $failWith = null;

        public function call($command, array $arguments = []): int
        {
            $this->called[] = [(string) $command, $arguments];

            if ($this->failWith !== null) {
                throw $this->failWith;
            }

            return 0;
        }

        public function finish(string $environment = 'production', string $context = 'ctx'): void
        {
            $this->connectCloudflare($environment, $context);
        }
    };

    $definition = $probe->getDefinition();
    // The application's own option, which a bare command does not carry.
    $definition->addOption(new InputOption('no-interaction', 'n', InputOption::VALUE_NONE));
    $arrayInput = new ArrayInput($input, $definition);
    $probe->setInput($arrayInput);
    $probe->setOutput(new Illuminate\Console\OutputStyle($arrayInput, new Symfony\Component\Console\Output\BufferedOutput));

    return $probe;
}

afterEach(function (): void {
    putenv('LARAKUBE_CLOUDFLARE_TOKEN');
});

test('a scripted run sets up Cloudflare DNS and SSL only when it opts in', function (): void {
    putenv('LARAKUBE_CLOUDFLARE_TOKEN=cf-token');

    $without = cloudflareProbe(['--no-interaction' => true]);
    $without->finish();

    $with = cloudflareProbe(['--no-interaction' => true, '--cloudflare' => true]);
    $with->finish('production', 'larakube-1.2.3.4');

    expect($without->called)->toBe([])
        ->and($with->called)->toBe([
            ['tool:init', ['--tool' => 'external-dns', 'environment' => 'production', '--context' => 'larakube-1.2.3.4']],
            ['tls:init', ['environment' => 'production', '--context' => 'larakube-1.2.3.4']],
        ]);
});

test('--cloudflare without a token does nothing and says so', function (): void {
    putenv('LARAKUBE_CLOUDFLARE_TOKEN');

    $probe = cloudflareProbe(['--no-interaction' => true, '--cloudflare' => true]);
    $probe->finish();

    expect($probe->called)->toBe([]);
});

test('a Cloudflare step that fails never fails the server', function (): void {
    putenv('LARAKUBE_CLOUDFLARE_TOKEN=cf-token');

    $probe = cloudflareProbe(['--no-interaction' => true, '--cloudflare' => true]);
    $probe->failWith = new RuntimeException('Missing required --group');
    $probe->finish();

    // Both were still attempted: a failed DNS step must not skip SSL.
    expect(array_column($probe->called, 0))->toBe(['tool:init', 'tls:init']);
});
