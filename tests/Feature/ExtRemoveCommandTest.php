<?php

use App\Commands\ExtRemoveCommand;
use App\Data\ConfigData;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Artisan;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Spin up a throwaway LaraKube project (.larakube.json + optional Dockerfile.php),
 * chdir into it (ext:remove resolves the project from getcwd()), run the callback,
 * then always restore the original cwd and delete the temp dir.
 */
function extRemoveInProject(array $config, ?string $dockerfile, callable $fn): void
{
    $dir = sys_get_temp_dir().'/ext-remove-'.uniqid();
    mkdir($dir, 0755, true);
    file_put_contents($dir.'/.larakube.json', json_encode($config + ['name' => 'demo']));
    if ($dockerfile !== null) {
        file_put_contents($dir.'/Dockerfile.php', $dockerfile);
    }

    $original = getcwd();
    chdir($dir);

    try {
        $fn($dir);
    } finally {
        chdir($original);
        exec('rm -rf '.escapeshellarg($dir));
    }
}

test('ext:remove drops the extension from .larakube.json', function () {
    $config = ['additionalExtensions' => ['gd', 'imagick']];
    extRemoveInProject($config, "FROM php:8.5\nRUN install-php-extensions gd imagick\n", function (string $dir) {
        $code = Artisan::call('ext:remove', ['extension' => 'gd']);

        expect($code)->toBe(0);

        $saved = ConfigData::loadFromFile($dir);
        expect($saved->getAdditionalExtensions())->toBe(['imagick']);
    });
});

test('ext:remove strips the extension from the install-php-extensions line, preserving the rest', function () {
    $config = ['additionalExtensions' => ['gd', 'imagick']];
    extRemoveInProject($config, "FROM php:8.5\nRUN install-php-extensions gd imagick\n", function (string $dir) {
        Artisan::call('ext:remove', ['extension' => 'gd']);

        $dockerfile = file_get_contents($dir.'/Dockerfile.php');
        expect($dockerfile)->toContain('RUN install-php-extensions imagick')
            ->and($dockerfile)->not->toContain('gd');
    });
});

test('ext:remove is a clean no-op when the extension is not configured', function () {
    $config = ['additionalExtensions' => ['imagick']];
    extRemoveInProject($config, "FROM php:8.5\nRUN install-php-extensions imagick\n", function (string $dir) {
        $code = Artisan::call('ext:remove', ['extension' => 'gd']);

        expect($code)->toBe(0);

        $saved = ConfigData::loadFromFile($dir);
        expect($saved->getAdditionalExtensions())->toBe(['imagick']);
    });
});

test('ext:remove fails cleanly outside a LaraKube project', function () {
    $dir = sys_get_temp_dir().'/ext-remove-noproject-'.uniqid();
    mkdir($dir, 0755, true);
    $original = getcwd();
    chdir($dir);

    try {
        $code = Artisan::call('ext:remove', ['extension' => 'gd']);
        expect($code)->toBe(1);
    } finally {
        chdir($original);
        exec('rm -rf '.escapeshellarg($dir));
    }
});

test('ext:remove exits cleanly when no extension is passed and no additional extensions are configured', function () {
    $config = ['additionalExtensions' => []];
    extRemoveInProject($config, null, function (string $dir) {
        $this->artisan('ext:remove')
            ->assertExitCode(0)
            ->expectsOutputToContain('No custom PHP extensions are currently added to this project.');
    });
});

test('ext:remove prompts with select dropdown when no extension is passed and additional extensions exist', function () {
    $config = ['additionalExtensions' => ['gd', 'imagick']];
    extRemoveInProject($config, "FROM php:8.5\nRUN install-php-extensions gd imagick\n", function (string $dir) {
        Prompt::fake([
            Key::ENTER, // pick the first option (gd) from the list
        ]);

        $command = app(ExtRemoveCommand::class);
        $input = new ArrayInput([]);
        $input->bind($command->getDefinition());
        $input->setInteractive(true);
        $output = new BufferedOutput;

        $command->setInput($input);
        $command->setOutput(new OutputStyle($input, $output));

        try {
            $code = $command->handle();
            expect($code)->toBe(0);

            $saved = ConfigData::loadFromFile($dir);
            expect($saved->getAdditionalExtensions())->toBe(['imagick']);
        } finally {
            Prompt::interactive(false);
        }
    });
});
