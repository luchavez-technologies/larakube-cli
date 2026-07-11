<?php

/**
 * `larakube new` must accept every native `laravel new` flag (--teams,
 * --workos, --branch, …). ignoreValidationErrors() alone was NOT enough:
 * Symfony aborts input binding at the first unknown option, so anything after
 * it — including the app name and --fast — never bound, dumping the user into
 * an unexpected name prompt (a hard NonInteractiveValidationException under
 * --no-interaction). Declaring the installer's flags lets binding complete;
 * these tests bind real command-line strings against the command definition
 * to prove it.
 */

use App\Commands\NewCommand;
use App\Enums\PackageManager;
use App\Enums\ServerVariation;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\StringInput;

function bindNewCommandInput(string $cli): StringInput
{
    $input = new StringInput($cli);
    $input->bind((new NewCommand)->getDefinition());

    return $input;
}

test('flags before the name no longer swallow the name argument', function () {
    $input = bindNewCommandInput('--teams --fast myapp');

    expect($input->getArgument('name'))->toBe('myapp')
        ->and($input->getOption('teams'))->toBeTrue()
        ->and($input->getOption('fast'))->toBeTrue();
});

test('value-taking installer flags consume their values correctly', function () {
    $input = bindNewCommandInput('--branch develop --using acme/kit myapp');

    expect($input->getArgument('name'))->toBe('myapp')
        ->and($input->getOption('branch'))->toBe('develop')
        ->and($input->getOption('using'))->toBe('acme/kit');
});

test('--database stays a LaraKube boolean (cache/scout driver), not the installer value flag', function () {
    // DB selection for the app goes through --mysql/--pgsql/…;
    // runLaravelNew() always scaffolds with --database=sqlite regardless.
    $definition = (new NewCommand)->getDefinition();

    expect($definition->getOption('database')->acceptValue())->toBeFalse();
});

test('every documented laravel new flag is bindable', function () {
    $input = bindNewCommandInput(
        '--dev --git --github --organization acme --react --svelte --vue --livewire '
        .'--livewire-class-components --workos --teams --no-authentication --pest --phpunit '
        .'--npm --pnpm --bun --yarn --no-node --boost --no-boost --force myapp',
    );

    expect($input->getArgument('name'))->toBe('myapp')
        ->and($input->getOption('workos'))->toBeTrue()
        ->and($input->getOption('no-authentication'))->toBeTrue()
        ->and($input->getOption('organization'))->toBe('acme');
});

test('--fast defaults build a config without crashing alongside installer flags', function () {
    // Pre-existing bug surfaced by binding now completing: the --fast branch
    // called ConfigData::hasServerVariation()/hasPackageManager(), which
    // didn't exist — `larakube new --fast x` fataled before this fix.
    $command = new class extends NewCommand
    {
        public function bindOptions(array $options): void
        {
            $this->input = new ArrayInput($options, $this->getDefinition());
        }

        public function config(): App\Data\ConfigData
        {
            return $this->buildConfigFromFlags();
        }
    };

    $command->bindOptions(['--fast' => true, '--teams' => true]);
    $config = $command->config();

    expect($config->getServerVariation())->toBe(ServerVariation::FRANKENPHP)
        ->and($config->hasPackageManager())->toBeTrue()
        ->and($config->getPackageManager())->toBe(PackageManager::NPM);
});

test('architectural enum flags coexist with the installer flags (no duplicate-option crash)', function () {
    // --react/--vue/--npm etc. are registered by addArchitecturalOptions()
    // first; the installer list must skip them, not fatal on redeclaration.
    $definition = (new NewCommand)->getDefinition();

    expect($definition->hasOption('react'))->toBeTrue()
        ->and($definition->hasOption('teams'))->toBeTrue()
        ->and($definition->hasOption('fast'))->toBeTrue();
});
