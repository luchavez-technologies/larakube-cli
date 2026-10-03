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
            ->and('my-app')->toMatch('/'.$name['pattern'].'/')
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

test('Statamic and Next.js ask their driver questions by flag, with none spelled --no-storage', function (): void {
    $catalog = collect(newFrameworksCatalog())->keyBy('slug');
    $statamic = collect($catalog['statamic']['fields'])->keyBy('key');
    $nextjs = collect($catalog['nextjs']['fields'])->keyBy('key');

    expect($statamic->keys()->all())->toBe(['name', 'email', 'php', 'features', 'database', 'cache', 'storage', 'search', 'content', 'starterKit'])
        ->and(array_column($statamic['php']['options'], 'value'))->not->toContain('8.1')
        ->and($statamic['cache']['forcedWhen'][0]['value'])->toBe('redis')
        ->and(collect($statamic['storage']['options'])->firstWhere('value', 'none')['flag'])->toBe('--no-storage')
        ->and($nextjs->keys()->all())->toBe(['name', 'database', 'storage', 'search'])
        ->and(array_column($nextjs['database']['options'], 'value'))->toBe(['postgres', 'mysql', 'mariadb'])
        ->and(collect($nextjs['database']['options'])->firstWhere('value', 'postgres')['recommended'])->toBeTrue();
});

test('Vite, Astro and Docusaurus offer a curated template that the CLI passes straight through', function (): void {
    $catalog = collect(newFrameworksCatalog())->keyBy('slug');

    foreach (['vite' => 'react-ts', 'astro' => 'minimal', 'docusaurus' => 'classic'] as $slug => $default) {
        $template = collect($catalog[$slug]['fields'])->firstWhere('key', 'template');

        expect($template['flag'])->toBe('--template=')
            ->and($template['default'])->toBe($default)
            ->and(array_column($template['options'], 'value'))->toContain($default);
    }
});

test('the catalog names its categories, each framework\'s fixed arguments and what is asked up front', function (): void {
    Artisan::call('new:frameworks --json');
    $payload = json_decode(trim(Artisan::output()), true);
    $frameworks = collect($payload['frameworks'])->keyBy('slug');
    $laravel = collect($frameworks['laravel']['fields'])->keyBy('key');

    expect(array_column($payload['categories'], 'id'))->toBe(['fullstack', 'cms', 'frontend', 'docs'])
        ->and($frameworks['laravel']['args'])->toBe(['--fast'])
        ->and($frameworks['statamic']['args'])->toBe(['--fast', '--no-plex'])
        ->and($frameworks['nextjs']['args'])->toBe(['--fast', '--no-plex'])
        ->and($laravel['database']['group'])->toBe('essential')
        ->and($laravel['features']['group'])->toBe('advanced')
        ->and($laravel['database']['suggested'])->toBe('postgres')
        ->and($laravel['server']['suggested'])->toBe('fpm-nginx');

    foreach ($frameworks as $framework) {
        expect($framework['category'])->toBeIn(array_column($payload['categories'], 'id'));
    }
});

test('every server framework asks database, cache, storage and search from one shared list', function (): void {
    $catalog = collect(newFrameworksCatalog())->keyBy('slug');

    foreach (['django', 'fastapi', 'nestjs', 'adonisjs', 'springboot', 'dotnet', 'gin', 'axum'] as $slug) {
        $fields = collect($catalog[$slug]['fields'])->keyBy('key');

        expect($fields->keys()->all())->toBe(['name', 'database', 'cache', 'storage', 'search'], $slug)
            ->and($fields['database']['default'])->toBe('postgres')
            ->and(array_column($fields['storage']['options'], 'value'))->toContain('none')
            ->and(array_column($fields['search']['options'], 'flag'))->toBe([null, '--meilisearch', '--typesense']);
    }

    $wordpress = collect($catalog['wordpress']['fields'])->keyBy('key');

    expect(array_column($wordpress['database']['options'], 'value'))->toBe(['mysql', 'mariadb'])
        ->and(array_column($wordpress['storage']['options'], 'value'))->not->toContain('none')
        ->and($wordpress->keys()->all())->toBe(['name', 'php', 'database', 'cache', 'storage', 'search'])
        ->and(array_column(collect($catalog['django']['fields'])->keyBy('key')['cache']['options'], 'value'))->toContain('database');
});

test('the server stack questions are answered by flags, then by --fast, and never prompt', function (array $input, string $expected): void {
    $probe = new class extends LaravelZero\Framework\Commands\Command
    {
        use App\Traits\AsksServerStack;

        protected $signature = 'probe:stack {--fast}';

        public function handle(): int
        {
            $fw = AppFramework::DJANGO;
            $this->line(implode(',', [
                $this->askDatabase($fw)->value,
                $this->askCache($fw)->value,
                $this->askStorage($fw)?->value ?? 'none',
                $this->askSearch($fw)?->value ?? 'none',
            ]));

            return 0;
        }
    };
    Artisan::registerCommand($probe);

    Artisan::call('probe:stack', $input + ['--no-interaction' => true]);

    expect(trim(Artisan::output()))->toBe($expected);
})->with([
    '--fast defaults' => [['--fast' => true], 'postgres,redis,minio,none'],
    'flags win' => [['--fast' => true, '--mysql' => true, '--memcached' => true, '--no-storage' => true, '--typesense' => true], 'mysql,memcached,none,typesense'],
    'a database cache for Django' => [['--fast' => true, '--database' => true], 'postgres,database,minio,none'],
]);
