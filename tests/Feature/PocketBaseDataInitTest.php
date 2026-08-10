<?php

use App\Exceptions\MissingFlagException;
use Illuminate\Support\Facades\Process;

test('data:init --engine=pocketbase deploys pocketbase stack and creates pvc', function () {
    Process::fake([
        '*create namespace*' => Process::result(output: 'created'),
        '*get secret*' => Process::result(output: ''),
        '*apply*' => Process::result(output: 'created'),
        '*rollout status*' => Process::result(output: 'successfully rolled out'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('data:init local --engine=pocketbase --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('Applying PocketBase manifests...')
        ->expectsOutputToContain('PocketBase Data / Headless CMS stack is live.');
});

test('data:init uses engine label override when prompting for host', function () {
    Process::fake([
        '*create namespace*' => Process::result(output: 'created'),
        '*get secret*' => Process::result(output: ''),
        '*apply*' => Process::result(output: 'created'),
        '*rollout status*' => Process::result(output: 'successfully rolled out'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('data:init production --domain=pocket.luchtech.dev --engine=pocketbase --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('Applying PocketBase manifests...')
        ->expectsOutputToContain('PocketBase Data / Headless CMS stack is live.');
});

test('data:init --engine=directus deploys directus stack using commons postgres', function () {
    Process::fake([
        '*plex-commons*' => Process::result(output: '{"services":{"postgres":{"enabled":true},"redis":{"enabled":true},"seaweedfs":{"enabled":true}}}'),
        '*plex-registry*' => Process::result(output: '{"tenants":{}}'),
        '*plex-admin*' => Process::result(output: base64_encode('s3-access-key')),
        '*create namespace*' => Process::result(output: 'created'),
        '*get secret*' => Process::result(output: 'secret-val'),
        '*exec*' => Process::result(output: 'success'),
        '*apply*' => Process::result(output: 'created'),
        '*rollout status*' => Process::result(output: 'successfully rolled out'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('data:init local --engine=directus --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('Applying Directus manifests...')
        ->expectsOutputToContain('Directus Data / Headless CMS stack is live.');
});

test('data:init records which engine an instance runs in the cluster registry', function () {
    // Nothing about a Data instance's host or URL reveals which engine it
    // runs — data:show/tool:list --json need this recorded, not just baked
    // into the manifest's env vars.
    $captured = null;

    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(output: ''),
        // Must come BEFORE the broad '*apply*' pattern below: saveToolRegistry()'s
        // own command pipes into `kubectl apply -f -`, which '*apply*' would
        // otherwise match first (Process::fake matches in array order).
        '*create secret generic larakube-tools-registry*' => function ($process) use (&$captured) {
            if (preg_match('/--from-file=registry\.json=(\S+)/', $process->command, $m)) {
                $captured = json_decode(file_get_contents($m[1]), true);
            }

            return Process::result();
        },
        '*create namespace*' => Process::result(output: 'created'),
        '*get secret*' => Process::result(output: ''),
        '*apply*' => Process::result(output: 'created'),
        '*rollout status*' => Process::result(output: 'successfully rolled out'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('data:init local --engine=pocketbase --force')->assertExitCode(0);

    expect($captured)->not->toBeNull();
    $dataEntry = collect($captured)->firstWhere('tool', 'data');
    expect($dataEntry)->not->toBeNull()
        ->and($dataEntry['engine'])->toBe('pocketbase');
});

test('data:init --domain resolves a distinct instance from the given host, not main\'s', function () {
    // Regression guard for the incident that started this whole pass
    // (2026-08-08): PocketBase and Directus both defaulted straight to
    // 'main' and collided on the same host. --domain now means "this exact
    // host" (see ResolvesToolHost::sanitizeDomainInput() — no auto-prefixing),
    // and the instance identifier is derived from it, so a different --domain
    // naturally lands on a different instance with no separate name needed.
    Process::fake([
        '*create namespace*' => Process::result(output: 'created'),
        '*get secret*' => Process::result(output: ''),
        '*apply*' => Process::result(output: 'created'),
        '*rollout status*' => Process::result(output: 'successfully rolled out'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('data:init local --engine=pocketbase --domain=blog.example.com --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('https://blog.example.com')
        ->doesntExpectOutputToContain('https://data.');
});

test('data:init --alias registers an additional hostname on the same instance\'s Ingress', function () {
    Process::fake([
        '*create namespace*' => Process::result(output: 'created'),
        '*get secret*' => Process::result(output: ''),
        '*apply*' => Process::result(output: 'created'),
        '*rollout status*' => Process::result(output: 'successfully rolled out'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('data:init local --engine=pocketbase --alias=alt.example.com --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('https://alt.example.com');
});

test('data:remove --domain derives the same instance data:init would have, not main\'s', function () {
    // The --domain given here must resolve to the SAME instance identifier
    // (via ClusterTool::instanceSlugFromHost() — the full host, dashed, no
    // auto-prefixing) that data:init would have derived from the identical
    // value, so removal always targets what you actually meant, not the
    // default instance.
    Process::fake([
        '*get deployment data-pocketbase-blog-example-com*' => Process::result(output: 'data-pocketbase-blog-example-com   1/1   1   1   10d'),
        '*get deployment data-directus-blog-example-com*' => Process::result(output: ''),
        '*delete*' => Process::result(output: 'deleted'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('data:remove local --domain=blog.example.com --force')->assertExitCode(0);

    Process::assertRan(fn ($process) => str_contains($process->command, 'delete')
        && str_contains($process->command, 'deployment/data-pocketbase-blog-example-com')
        && ! str_contains($process->command, 'secret/data-secrets '));
});

test('data:remove --engine=pocketbase removes pocketbase resources', function () {
    Process::fake([
        '*delete*' => Process::result(output: 'deleted'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('data:remove local --engine=pocketbase --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('Removing Data resources...');
});

test('data:remove tears down pocketbase\'s own Service and Ingress, not just Directus-shaped names', function () {
    // Regression guard for a live collision (2026-08-08): teardown() only
    // ever deleted service/data + ingress/data (Directus's actual names) and
    // service/data-{instance} + ingress/data-{instance} — never PocketBase's
    // real names (service/data-pocketbase, ingress/data-pocketbase-ingress).
    // Every past data:remove left those orphaned, and the next data:init for
    // either engine collided with them on the shared Data host.
    Process::fake([
        '*delete*' => Process::result(output: 'deleted'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('data:remove local --engine=pocketbase --force')->assertExitCode(0);

    Process::assertRan(fn ($process) => str_contains($process->command, 'service/data-pocketbase')
        && str_contains($process->command, 'ingress/data-pocketbase-ingress')
        && str_contains($process->command, 'configmap/data-pocketbase-hooks'));
});

test('data:remove asks which engine when both are deployed for the same instance, rather than guessing', function () {
    // Regression guard for the exact scare that prompted this redesign
    // (2026-08-08): the old teardown() always deleted BOTH engine-shaped
    // resource sets unconditionally — safe only under the old one-engine-
    // per-Data assumption, wrong now that a stale/pre-fix cluster (or a
    // failed swap) can genuinely have both live at once. Never silently
    // pick one. Tests run non-interactively (RequiresFlagsWhenNonInteractive
    // ::cannotPrompt() is true under runningUnitTests()), so this exercises
    // the "ask" path as the flag-required failure — interactively it's a
    // select() prompt instead, per flagOrPrompt()'s contract.
    Process::fake([
        '*get deployment data-directus*' => Process::result(output: 'data-directus   1/1   1   1   10d'),
        '*get deployment data-pocketbase*' => Process::result(output: 'data-pocketbase   1/1   1   1   10d'),
        '*' => Process::result(output: ''),
    ]);

    // MissingFlagException is thrown before any delete command runs — the
    // ->throws() assertion below is itself the proof nothing was deleted.
    $this->artisan('data:remove local --force')->run();
})->throws(MissingFlagException::class, 'Missing required --engine');

test('data:remove --engine=all removes both when both are genuinely deployed', function () {
    Process::fake([
        '*get deployment data-directus*' => Process::result(output: 'data-directus   1/1   1   1   10d'),
        '*get deployment data-pocketbase*' => Process::result(output: 'data-pocketbase   1/1   1   1   10d'),
        '*delete*' => Process::result(output: 'deleted'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('data:remove local --engine=all --force')->assertExitCode(0);

    Process::assertRan(fn ($process) => str_contains($process->command, 'delete')
        && str_contains($process->command, 'deployment/data-directus')
        && str_contains($process->command, 'deployment/data-pocketbase'));
});

test('data:remove auto-detects the single engine actually deployed, without needing --engine', function () {
    Process::fake([
        '*get deployment data-directus*' => Process::result(output: 'data-directus   1/1   1   1   10d'),
        '*get deployment data-pocketbase*' => Process::result(output: ''),
        '*delete*' => Process::result(output: 'deleted'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('data:remove local --force')->assertExitCode(0);

    Process::assertRan(fn ($process) => str_contains($process->command, 'delete')
        && str_contains($process->command, 'deployment/data-directus')
        && ! str_contains($process->command, 'deployment/data-pocketbase'));
});
