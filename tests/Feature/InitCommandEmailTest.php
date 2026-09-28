<?php

use App\Commands\InitCommand;
use Symfony\Component\Console\Input\StringInput;

/**
 * The wizard's Let's Encrypt email question is required, so a headless init
 * of a PHP project (LaraKube Desktop, CI) on a machine with no stored email
 * needs --email to answer it, the same as `new`.
 */
test('init takes --email and applies it before the wizard asks', function (): void {
    $input = new StringInput('--framework=laravel --email=dev@example.com --fast');
    $input->bind((new InitCommand)->getDefinition());

    expect($input->getOption('email'))->toBe('dev@example.com');

    $source = (string) file_get_contents(base_path('app/Commands/InitCommand.php'));
    $apply = strpos($source, '$this->applyEmailOption($config)');
    $wizard = strpos($source, '$this->gatherConfig($config, forcePrompts: $isReinit)');

    expect($apply)->not->toBeFalse()
        ->and($apply)->toBeLessThan($wizard);
});
