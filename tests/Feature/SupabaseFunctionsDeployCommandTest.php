<?php

use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;
use Tests\Support\FakeToolRegistry;

/** A functions folder laid out like the Supabase CLI's: one sub-folder per function. */
function supabaseFunctionsFolder(array $folders): string
{
    $root = (new TemporaryDirectory)->create()->path('functions');
    foreach ($folders as $folder) {
        mkdir("{$root}/{$folder}", 0755, true);
        file_put_contents("{$root}/{$folder}/index.ts", 'Deno.serve(() => new Response("ok"));');
    }

    return $root;
}

/** @return array<string, mixed> */
function supabaseFunctionsFakes(string $deploymentFound = 'deployment.apps/supabase-functions-supabase-example-com'): array
{
    FakeToolRegistry::install([['tool' => 'supabase', 'instance' => 'supabase-example-com', 'host' => 'supabase.example.com']]);

    return [
        '*get deployment supabase-functions-supabase-example-com*' => Process::result(output: $deploymentFound),
        '*tar -cf*' => Process::result(output: ''),
        '*rollout*' => Process::result(output: 'successfully rolled out'),
        '*' => Process::result(output: ''),
    ];
}

test('supabase:functions:deploy copies every function folder into Edge Runtime, then restarts it', function (): void {
    Process::fake(supabaseFunctionsFakes());
    $path = supabaseFunctionsFolder(['hello', 'send-mail', '_shared']);

    $this->artisan("supabase:functions:deploy prod --context=ctx --path={$path}")
        ->expectsOutputToContain('https://supabase.example.com/functions/v1/hello')
        ->expectsOutputToContain('https://supabase.example.com/functions/v1/send-mail')
        ->doesntExpectOutputToContain('/functions/v1/_shared')
        ->assertExitCode(0);

    Process::assertRan(fn ($p): bool => str_contains((string) $p->command, "tar -cf - -C '{$path}'")
        && str_contains((string) $p->command, 'exec -i deploy/supabase-functions-supabase-example-com')
        && str_contains((string) $p->command, "'\\''hello'\\''")
        && str_contains((string) $p->command, 'rm -rf --'));
    Process::assertRan(fn ($p): bool => str_contains((string) $p->command, 'rollout restart deployment/supabase-functions-supabase-example-com'));
});

test('supabase:functions:deploy --function= deploys only that one, plus shared folders it may import', function (): void {
    Process::fake(supabaseFunctionsFakes());
    $path = supabaseFunctionsFolder(['hello', 'send-mail', '_shared']);

    $this->artisan("supabase:functions:deploy prod --context=ctx --path={$path} --function=hello")
        ->assertExitCode(0);

    Process::assertRan(fn ($p): bool => str_contains((string) $p->command, 'tar -cf')
        && str_contains((string) $p->command, 'hello')
        && str_contains((string) $p->command, '_shared')
        && ! str_contains((string) $p->command, 'send-mail'));
});

test('supabase:functions:deploy never replaces Edge Runtime\'s own main router', function (): void {
    Process::fake(supabaseFunctionsFakes());
    $path = supabaseFunctionsFolder(['main', 'hello']);

    $this->artisan("supabase:functions:deploy prod --context=ctx --path={$path}")
        ->expectsOutputToContain("would replace Edge Runtime's own router")
        ->assertExitCode(1);

    Process::assertNotRan(fn ($p): bool => str_contains((string) $p->command, 'tar -cf'));
});

test('supabase:functions:deploy refuses when Edge Functions are turned off', function (): void {
    Process::fake(supabaseFunctionsFakes(deploymentFound: ''));
    $path = supabaseFunctionsFolder(['hello']);

    $this->artisan("supabase:functions:deploy prod --context=ctx --path={$path}")
        ->expectsOutputToContain('Edge Functions are turned off')
        ->assertExitCode(1);

    Process::assertNotRan(fn ($p): bool => str_contains((string) $p->command, 'tar -cf'));
});

test('supabase:functions:deploy names a missing function and a missing folder', function (): void {
    Process::fake(supabaseFunctionsFakes());
    $path = supabaseFunctionsFolder(['hello']);

    $this->artisan("supabase:functions:deploy prod --context=ctx --path={$path} --function=nope")
        ->expectsOutputToContain('Not found in')
        ->assertExitCode(1);

    $this->artisan('supabase:functions:deploy prod --context=ctx --path=/no/such/folder')
        ->expectsOutputToContain('No functions folder at /no/such/folder')
        ->assertExitCode(1);
});
