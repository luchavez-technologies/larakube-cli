<?php

use App\Traits\InteractsWithDocker;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/** A command with the image-building and sudo helpers, running every command it is given into $ran. */
function localImageHarness(bool $podman): object
{
    return new class($podman)
    {
        use InteractsWithDocker, StreamsProcessOutput;

        public array $ran = [];

        public function __construct(private bool $podman) {}

        public function laraKubeInfo(string $message): void {}

        public function laraKubeError(string $message): void {}

        public function line(string $text): void {}

        public function runtimeIsPodman(): bool
        {
            return $this->podman;
        }

        public function containerRuntime(): string
        {
            return $this->podman ? 'podman' : 'docker';
        }

        public function build(string $dockerfile): bool
        {
            return $this->buildTargetedImage('shop:local', $dockerfile, dirname($dockerfile), 1000, 1000);
        }

        public function warm(): void
        {
            $this->warmSudo();
        }

        public function sideloadImage(): bool
        {
            return $this->sideloadIntoK3s('shop:local');
        }

        protected function runStreaming(string $command, ...$rest): int
        {
            $this->ran[] = $command;

            return 0;
        }

        protected function runInteractive(string $command): int
        {
            $this->ran[] = $command;

            return 0;
        }
    };
}

test('Podman builds the app image under the name the cluster will look it up by', function (): void {
    Process::fake(['*' => Process::result(output: '')]);
    $dir = TemporaryDirectory::make()->deleteWhenDestroyed();
    file_put_contents($dir->path().'/Dockerfile.php', "FROM scratch\n");
    $harness = localImageHarness(podman: true);

    $harness->build($dir->path().'/Dockerfile.php');

    $build = collect($harness->ran)->first(fn (string $command): bool => str_contains($command, ' build '));

    expect($build)->toContain("-t 'docker.io/library/shop:local'")
        ->not->toContain('localhost/');
});

test('Docker keeps the name as given', function (): void {
    Process::fake(['*' => Process::result(output: '')]);
    $dir = TemporaryDirectory::make()->deleteWhenDestroyed();
    file_put_contents($dir->path().'/Dockerfile.php', "FROM scratch\n");
    $harness = localImageHarness(podman: false);

    $harness->build($dir->path().'/Dockerfile.php');

    expect(collect($harness->ran)->first(fn (string $command): bool => str_contains($command, ' build ')))->toContain("-t 'shop:local'");
});

test('sudo is only warmed up when it would ask for a password', function (): void {
    Process::fake(['sudo -n true' => Process::result(exitCode: 0)]);
    $harness = localImageHarness(podman: true);
    $harness->warm();

    expect($harness->ran)->not->toContain('sudo -v');

    Process::fake(['sudo -n true' => Process::result(exitCode: 1)]);
    $harness = localImageHarness(podman: true);
    $harness->warm();

    expect($harness->ran)->toContain('sudo -v');
});

test('an image that could not be loaded into k3s is reported as a failure', function (): void {
    Process::fake(['*' => Process::result(output: '')]);

    $harness = new class
    {
        use InteractsWithDocker, StreamsProcessOutput;

        public function laraKubeInfo(string $message): void {}

        public function laraKubeError(string $message): void {}

        public function line(string $text): void {}

        public function runtimeIsPodman(): bool
        {
            return false;
        }

        public function containerRuntime(): string
        {
            return 'docker';
        }

        public function sideloadImage(): bool
        {
            return $this->sideloadIntoK3s('shop:local');
        }

        protected function runStreaming(string $command, ...$rest): int
        {
            return 1;
        }

        protected function runInteractive(string $command): int
        {
            return 0;
        }
    };

    expect($harness->sideloadImage())->toBeFalse();
});
