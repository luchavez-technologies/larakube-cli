<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeToolRegistry;

/** @return array<string, mixed> */
function supabaseFunctionsListFakes(string $deploymentFound, string $listing = ''): array
{
    FakeToolRegistry::install([['tool' => 'supabase', 'instance' => 'supabase-example-com', 'host' => 'supabase.example.com']]);

    return [
        '*get deployment supabase-functions-supabase-example-com*' => Process::result(output: $deploymentFound),
        '*find /home/deno/functions*' => Process::result(output: $listing),
        '*' => Process::result(output: ''),
    ];
}

/** @return array<string, mixed> */
function supabaseFunctionsListJson(): array
{
    Artisan::call('supabase:functions:list', ['environment' => 'prod', '--context' => 'ctx', '--json' => true]);

    return (array) json_decode(Artisan::output(), true);
}

test('supabase:functions:list --json lists each function with its URL, shared folders last and without one', function (): void {
    Process::fake(supabaseFunctionsListFakes(
        'deployment.apps/supabase-functions-supabase-example-com',
        "send-mail\t1795000000.5\n_shared\t1795000100.0\nhello\t1795000200.0\n",
    ));

    $result = supabaseFunctionsListJson();

    expect($result['success'])->toBeTrue()
        ->and($result['enabled'])->toBeTrue()
        ->and($result['host'])->toBe('supabase.example.com')
        ->and(array_column($result['functions'], 'name'))->toBe(['hello', 'send-mail', '_shared'])
        ->and($result['functions'][0]['url'])->toBe('https://supabase.example.com/functions/v1/hello')
        ->and($result['functions'][2])->toMatchArray(['shared' => true, 'url' => null])
        ->and($result['functions'][0]['updatedAt'])->toBe(date(DATE_ATOM, 1795000200));

    // Edge Runtime's own router never shows up as a function.
    Process::assertRan(fn ($p): bool => str_contains((string) $p->command, '-not -name main'));
});

test('supabase:functions:list --json says when Edge Functions are turned off, without reading the pod', function (): void {
    Process::fake(supabaseFunctionsListFakes(''));

    $result = supabaseFunctionsListJson();

    expect($result)->toMatchArray(['success' => true, 'enabled' => false, 'functions' => []]);
    Process::assertNotRan(fn ($p): bool => str_contains((string) $p->command, 'find /home/deno/functions'));
});

test('supabase:functions:list prints a table, or a hint when nothing is deployed', function (): void {
    Process::fake(supabaseFunctionsListFakes('deployment.apps/supabase-functions-supabase-example-com', "hello\t1795000200.0\n"));
    $this->artisan('supabase:functions:list prod --context=ctx')
        ->expectsOutputToContain('https://supabase.example.com/functions/v1/hello')
        ->assertExitCode(0);

    Process::fake(supabaseFunctionsListFakes('deployment.apps/supabase-functions-supabase-example-com'));
    $this->artisan('supabase:functions:list prod --context=ctx')
        ->expectsOutputToContain('No Edge Functions deployed yet')
        ->assertExitCode(0);
});

test('supabase:functions:list --json reports a failure when Supabase is not installed', function (): void {
    FakeToolRegistry::install();
    Process::fake(['*' => Process::result(output: '')]);

    $result = supabaseFunctionsListJson();

    expect($result)->toBe(['success' => false, 'error' => 'Supabase is not installed on this cluster.']);
});
