<?php

use Illuminate\Support\Facades\Process;
use Tests\Support\FakeToolRegistry;

/** @return array<string, mixed> */
function supabaseFunctionsDeleteFakes(string $listing = "functions\t1795000000.0\nhello\t1795000100.0\n"): array
{
    FakeToolRegistry::install([['tool' => 'supabase', 'instance' => 'supabase-example-com', 'host' => 'supabase.example.com']]);

    return [
        '*get deployment supabase-functions-supabase-example-com*' => Process::result(output: 'deployment.apps/supabase-functions-supabase-example-com'),
        '*find /home/deno/functions*' => Process::result(output: $listing),
        '*rm -rf*' => Process::result(output: ''),
        '*rollout*' => Process::result(output: 'successfully rolled out'),
        '*' => Process::result(output: ''),
    ];
}

test('supabase:functions:delete removes the folder from Edge Runtime, then restarts it', function (): void {
    Process::fake(supabaseFunctionsDeleteFakes());

    $this->artisan('supabase:functions:delete prod --context=ctx --function=functions --force')
        ->expectsOutputToContain('Deleted functions.')
        ->assertExitCode(0);

    Process::assertRan(fn ($p): bool => str_contains((string) $p->command, 'exec deploy/supabase-functions-supabase-example-com')
        && str_contains((string) $p->command, "rm -rf -- '\\''functions'\\''"));
    Process::assertRan(fn ($p): bool => str_contains((string) $p->command, 'rollout restart deployment/supabase-functions-supabase-example-com'));
});

test('supabase:functions:delete refuses a function that is not deployed, and never deletes main', function (): void {
    Process::fake(supabaseFunctionsDeleteFakes());

    $this->artisan('supabase:functions:delete prod --context=ctx --function=nope --force')
        ->expectsOutputToContain('No Edge Function named nope')
        ->assertExitCode(1);

    $this->artisan('supabase:functions:delete prod --context=ctx --function=main --force')
        ->expectsOutputToContain("'main' is Edge Runtime's own router")
        ->assertExitCode(1);

    Process::assertNotRan(fn ($p): bool => str_contains((string) $p->command, 'rm -rf'));
});

test('supabase:functions:delete needs --function when it cannot ask which', function (): void {
    Process::fake(supabaseFunctionsDeleteFakes());

    $this->artisan('supabase:functions:delete prod --context=ctx --no-interaction');
})->throws(App\Exceptions\MissingFlagException::class, 'Missing required --function');

test('supabase:functions:delete needs --force when it cannot ask to confirm, and deletes nothing', function (): void {
    Process::fake(supabaseFunctionsDeleteFakes());

    try {
        $this->artisan('supabase:functions:delete prod --context=ctx --function=hello --no-interaction');
    } finally {
        Process::assertNotRan(fn ($p): bool => str_contains((string) $p->command, 'rm -rf'));
    }
})->throws(App\Exceptions\MissingFlagException::class, 'Missing required --force');
