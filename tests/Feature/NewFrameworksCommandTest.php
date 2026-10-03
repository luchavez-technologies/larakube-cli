<?php

use App\Enums\AppFramework;
use Illuminate\Support\Facades\Artisan;

function newFrameworksCatalog(): array
{
    $exit = Artisan::call('new:frameworks --json');
    $payload = json_decode(trim(Artisan::output()), true);

    expect($exit)->toBe(0)->and($payload['success'])->toBeTrue();

    return $payload['frameworks'];
}

test('new:frameworks lists every framework the CLI can scaffold, with what a picker needs', function (): void {
    $frameworks = collect(newFrameworksCatalog())->keyBy('slug');

    expect($frameworks->keys()->all())->toBe(array_map(fn (AppFramework $f): string => $f->value, AppFramework::cases()))
        ->and($frameworks['laravel'])->toMatchArray(['label' => 'Laravel', 'category' => 'fullstack', 'tech' => 'PHP', 'command' => 'new', 'logo' => 'laravel', 'initEmail' => true])
        ->and($frameworks['docusaurus']['command'])->toBe('docs:new')
        ->and($frameworks['docusaurus']['category'])->toBe('docs')
        ->and($frameworks['wordpress']['hidden'])->toBeTrue()
        ->and($frameworks['nextjs']['hidden'])->toBeFalse();

    foreach ($frameworks as $slug => $framework) {
        expect($framework['description'])->not->toBe('', "{$slug} has no description");
    }
});

test('every framework asks for its name, and a project name is validated by the CLI', function (): void {
    foreach (newFrameworksCatalog() as $framework) {
        $name = collect($framework['fields'])->firstWhere('key', 'name');

        expect($name)->toMatchArray(['type' => 'text', 'required' => true, 'arg' => 'positional', 'maxLength' => 50, 'reserved' => ['console']])
            ->and(preg_match('/'.$name['pattern'].'/', 'my-app'))->toBe(1)
            ->and(preg_match('/'.$name['pattern'].'/', 'My App'))->toBe(0);
    }
});

test('Laravel asks the same questions as new:options, plus the rules the wizard applies in code', function (): void {
    Artisan::call('new:options --json');
    $questions = collect(json_decode(trim(Artisan::output()), true)['questions'])->keyBy('key');
    $fields = collect(collect(newFrameworksCatalog())->firstWhere('slug', 'laravel')['fields'])->keyBy('key');

    foreach ($questions as $key => $question) {
        expect($fields[$key]['options'])->toBe($question['options'])
            ->and($fields[$key]['default'])->toBe($question['default'])
            ->and($fields[$key]['type'])->toBe($question['multiple'] ? 'multiselect' : 'select');
    }

    expect($fields['search']['visibleWhen'])->toBe(['features' => 'scout'])
        ->and($fields['cache']['forcedWhen'][0])->toBe(['when' => ['features' => 'horizon'], 'value' => 'redis'])
        ->and($fields['database']['defaultWhen'][0]['value'])->toBe('postgres')
        ->and($fields['server']['implies']['frankenphp'])->toBe(['features' => 'octane'])
        ->and($fields['features']['conflicts'])->toBe([['horizon', 'queues']]);
});

test('every flag the catalog names exists on the command that scaffolds the app', function (): void {
    $commands = Artisan::all();

    foreach (newFrameworksCatalog() as $framework) {
        $command = $commands[$framework['command']] ?? null;

        expect($command)->not->toBeNull("{$framework['slug']}: {$framework['command']} is not a command");

        $definition = $command->getDefinition();

        foreach ($framework['fields'] as $field) {
            if (($field['arg'] ?? null) === 'positional') {
                expect($definition->hasArgument($field['key']))->toBeTrue("{$framework['command']} has no argument {$field['key']}");
            }

            $flags = isset($field['flag']) ? [$field['flag']] : array_column($field['options'] ?? [], 'flag');

            foreach (array_filter($flags) as $flag) {
                $option = ltrim(rtrim($flag, '='), '-');

                expect($definition->hasOption($option))->toBeTrue("{$framework['command']} has no option --{$option} for {$field['key']}");
            }
        }
    }
});
