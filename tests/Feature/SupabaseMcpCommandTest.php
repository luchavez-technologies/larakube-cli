<?php

use Illuminate\Support\Facades\Process;
use Tests\Support\FakeToolRegistry;

test('supabase:mcp tunnels to Studio on 127.0.0.1 only and prints the Claude Code line', function (): void {
    FakeToolRegistry::install([['tool' => 'supabase', 'instance' => 'supabase-example-com', 'host' => 'supabase.example.com']]);
    Process::fake(['*' => Process::result(output: '')]);

    $this->artisan('supabase:mcp prod --context=ctx --port=54400')
        ->expectsOutputToContain('claude mcp add --transport http supabase "http://127.0.0.1:54400/api/mcp?features=docs%2Cdatabase%2Cdebugging%2Cdevelopment"')
        ->assertExitCode(0);

    Process::assertRan(fn ($p): bool => str_contains((string) $p->command, 'port-forward --address 127.0.0.1 -n')
        && str_contains((string) $p->command, 'svc/supabase-studio-supabase-example-com 54400:3000'));
});

test('supabase:mcp says so when Supabase is not installed, and opens nothing', function (): void {
    FakeToolRegistry::install();
    Process::fake(['*' => Process::result(output: '')]);

    $this->artisan('supabase:mcp prod --context=ctx')
        ->expectsOutputToContain('Supabase is not installed on this cluster.')
        ->assertExitCode(1);

    Process::assertNotRan(fn ($p): bool => str_contains((string) $p->command, 'port-forward'));
});

test('supabase:mcp refuses a privileged or out-of-range port', function (): void {
    Process::fake(['*' => Process::result(output: '')]);

    $this->artisan('supabase:mcp prod --context=ctx --port=80')
        ->expectsOutputToContain('--port must be between 1024 and 65535.')
        ->assertExitCode(1);
});
