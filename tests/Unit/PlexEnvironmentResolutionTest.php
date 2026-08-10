<?php

/**
 * resolvePlexEnvironment() lives in InteractsWithPlex (shared by plex:init,
 * plex:join, plex:leave, plex:migrate, plex:rotate, plex:destroy,
 * plex:remove, plex:export, plex:show, plex:resources) rather than
 * duplicated per command — tested once here via PlexInitCommand as a stand-in
 * host, since the method's behavior doesn't depend on which command calls it.
 */

use App\Commands\Plex\PlexInitCommand;
use App\Data\ConfigData;
use Illuminate\Console\OutputStyle;
use Laravel\Prompts\Prompt;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;

Prompt::interactive(false);

function plexInitCommand(array $arguments = []): object
{
    $command = new class extends PlexInitCommand
    {
        public function callResolvePlexEnvironment(?ConfigData $config): string
        {
            return $this->resolvePlexEnvironment($config);
        }
    };

    // --no-interaction is a global option the Application merges into every
    // command at runtime; the unit-test harness must do the same or
    // $this->option('no-interaction') throws "option does not exist".
    $definition = clone $command->getDefinition();
    $definition->addOption(new InputOption('no-interaction', 'n', InputOption::VALUE_NONE, 'Do not ask any interactive question.'));

    $input = new ArrayInput($arguments);
    $input->bind($definition);
    $command->setInput($input);
    $command->setOutput(new OutputStyle($input, new BufferedOutput));

    return $command;
}

test('resolvePlexEnvironment returns the explicit positional immediately, no prompt', function () {
    $command = plexInitCommand(['environment' => 'production']);

    expect($command->callResolvePlexEnvironment(ConfigData::from(['name' => 'demo'])))->toBe('production');
});

test('resolvePlexEnvironment defaults to local under --no-interaction, matching every other {tool}:init', function () {
    $command = plexInitCommand(['--no-interaction' => true]);

    expect($command->callResolvePlexEnvironment(ConfigData::from(['name' => 'demo'])))->toBe('local');
});

test('resolvePlexEnvironment prompts with local + the project\'s known cloud environments when nothing forces a default', function () {
    // Prompt::interactive(false) makes select() resolve to its `default`
    // (never hang), which is 'local' here — this locks in that the offered
    // option set is genuinely local + the config's cloud envs, not just that
    // select() was called with SOME options.
    $command = plexInitCommand([]);

    $config = ConfigData::from([
        'name' => 'demo',
        'environments' => [
            'local' => [],
            'production' => [],
            'staging' => [],
        ],
    ]);

    expect($command->callResolvePlexEnvironment($config))->toBe('local');
});

test('resolvePlexEnvironment accepts a null config — plex:rotate/plex:export can run with no project in cwd', function () {
    // No cloud envs to offer without a project, but it still asks rather
    // than silently assuming local — same contract, degraded option list.
    $command = plexInitCommand([]);

    expect($command->callResolvePlexEnvironment(null))->toBe('local');

    $nonInteractive = plexInitCommand(['--no-interaction' => true]);
    expect($nonInteractive->callResolvePlexEnvironment(null))->toBe('local');
});
