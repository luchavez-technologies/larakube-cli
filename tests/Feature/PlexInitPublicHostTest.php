<?php

use App\Commands\Plex\PlexInitCommand;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/** plex:init's public-address step, on a real or local cluster, run as Desktop runs it (--services, no prompt). */
function plexInitPublicHostProbe(bool $local, array $options = []): object
{
    return new class($local, $options) extends PlexInitCommand
    {
        public BufferedOutput $buffer;

        public function __construct(private bool $local, array $options)
        {
            parent::__construct();
            $this->input = new ArrayInput(['--services' => 'seaweedfs', ...$options], $this->getDefinition());
            $this->buffer = new BufferedOutput;
            $this->output = new OutputStyle($this->input, $this->buffer);
            // laraKubeWarn() renders through Termwind, which writes to its own output.
            Termwind\renderUsing($this->buffer);
        }

        /** @param  array<string, mixed>  $spec */
        public function hosts(array $spec): array
        {
            return $this->ensurePublicHosts($spec);
        }

        protected function targetsLocalCluster(): bool
        {
            return $this->local;
        }
    };
}

test('plex:init warns when object storage on a real server gets no public address', function (): void {
    $probe = plexInitPublicHostProbe(local: false);

    $spec = $probe->hosts(['services' => ['seaweedfs' => ['enabled' => true, 'port' => 8333]]]);

    expect($spec['services']['seaweedfs'])->not->toHaveKey('host')
        ->and($probe->buffer->fetch())->toContain('no public address');
});

test('plex:init takes --s3-host for an existing object storage engine without one, quietly', function (): void {
    $probe = plexInitPublicHostProbe(local: false, options: ['--s3-host' => 's3-hello-cloud.example.com']);

    $spec = $probe->hosts(['services' => ['seaweedfs' => ['enabled' => true, 'port' => 8333]]]);

    expect($spec['services']['seaweedfs']['host'])->toBe('s3-hello-cloud.example.com')
        ->and($probe->buffer->fetch())->not->toContain('no public address');
});

afterEach(fn () => Termwind\renderUsing(null));
