<?php

use App\Exceptions\MissingFlagException;
use App\Traits\InteractsWithToolRegistry;
use Illuminate\Support\Facades\Process;

uses(InteractsWithToolRegistry::class);

beforeEach(function () {
    @unlink(getcwd().'/.larakube.local.json');
    @unlink(getcwd().'/.larakube.json');
});

test('data:init --engine=pocketbase deploys pocketbase stack and creates pvc', function () {
    Process::fake([
        '*create namespace*' => Process::result(output: 'created'),
        '*get secret*' => Process::result(output: ''),
        '*apply*' => Process::result(output: 'created'),
        '*rollout status*' => Process::result(output: 'successfully rolled out'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('data:init local --engine=pocketbase --admin-email=admin@example.com --force')
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

    $this->artisan('data:init production --domain=pocket.luchtech.dev --engine=pocketbase --admin-email=admin@example.com --force')
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

    $this->artisan('data:init local --engine=directus --admin-email=admin@example.com --force')
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

    $this->artisan('data:init local --engine=pocketbase --admin-email=admin@example.com --force')->assertExitCode(0);

    expect($captured)->not->toBeNull();
    $dataEntry = collect($captured)->firstWhere('tool', 'data');
    expect($dataEntry)->not->toBeNull()
        ->and($dataEntry['engine'])->toBe('pocketbase');
});

test('data:init without --domain re-targets the existing main instance even when its host label differs from the service prefix', function () {
    // Regression guard (confirmed live 2026-08-09): DATA's default host is
    // pocket.luchtech.dev but the service hostPrefix is 'data', so a plain
    // re-run of data:init derived the slug 'pocket-luchtech-dev', deployed a
    // SECOND PocketBase (data-pocketbase-{slug}) and registered a duplicate
    // registry row next to 'main' — both pointing at the same host. Host
    // identity must win: re-run = update main in place, never a new instance.
    $captured = null;

    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(
            output: base64_encode((string) json_encode([
                ['tool' => 'data', 'instance' => 'main', 'aliases' => [], 'installedAt' => '2026-08-09T10:35:58+00:00', 'host' => 'pocket.luchtech.dev'],
            ])),
        ),
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

    $this->artisan('data:init production --engine=pocketbase --admin-email=admin@example.com --force --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('Applying PocketBase manifests...');

    // The manifest applied must be main's (data-pocketbase), not a slug instance's.
    Process::assertRan(fn ($p) => str_contains((string) $p->command, 'data-pocketbase'))
        ->assertNotRan(fn ($p) => str_contains((string) $p->command, 'data-pocketbase-pocket-luchtech-dev'));

    $dataEntries = collect($captured ?? [])->where('tool', 'data');
    expect($dataEntries)->toHaveCount(1)
        ->and($dataEntries->first()['instance'])->toBe('main')
        ->and($dataEntries->first()['host'])->toBe('pocket.luchtech.dev');
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

    $this->artisan('data:init local --engine=pocketbase --domain=blog.example.com --admin-email=admin@example.com --force')
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

    $this->artisan('data:init local --engine=pocketbase --alias=alt.example.com --admin-email=admin@example.com --force')
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

test('data:remove --domain removes EVERY instance registered for the host (duplicate cleanup)', function () {
    // Regression guard for the 2026-08-09 incident: main AND the buggy
    // host-derived slug both registered pocket.luchtech.dev. Removal means
    // "take down everything serving this host" — both instances must be
    // torn down and unregistered in one command, leaving a clean slate.
    $captured = null;
    $writes = [];

    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(
            output: base64_encode((string) json_encode([
                ['tool' => 'data', 'instance' => 'main', 'aliases' => [], 'installedAt' => '2026-08-09T10:35:58+00:00', 'host' => 'pocket.luchtech.dev'],
                ['tool' => 'data', 'instance' => 'pocket-luchtech-dev', 'aliases' => [], 'installedAt' => '2026-08-09T10:36:31+00:00', 'host' => 'pocket.luchtech.dev'],
            ])),
        ),
        '*create secret generic larakube-tools-registry*' => function ($process) use (&$captured, &$writes) {
            if (preg_match('/--from-file=registry\.json=(\S+)/', $process->command, $m)) {
                $decoded = json_decode(file_get_contents($m[1]), true);
                $writes[] = $decoded;
                $captured = $decoded;
            }

            return Process::result();
        },
        '*get deployment data-pocketbase*' => Process::result(output: 'data-pocketbase   1/1   1   1   10d'),
        '*get deployment data-directus*' => Process::result(output: ''),
        '*delete*' => Process::result(output: 'deleted'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('data:remove local --domain=pocket.luchtech.dev --force')->assertExitCode(0);

    Process::assertRan(fn ($process) => str_contains($process->command, 'delete')
        && str_contains($process->command, 'deployment/data-pocketbase')
        && ! str_contains($process->command, 'deployment/data-pocketbase-'))
        ->assertRan(fn ($process) => str_contains($process->command, 'delete')
            && str_contains($process->command, 'deployment/data-pocketbase-pocket-luchtech-dev'));

    // The loop ran ONE unregister per instance (two registry writes), each
    // dropping a DIFFERENT data entry — the fake re-seeds on every read, so
    // the second write still carries the other instance, which is expected;
    // what matters is both instances were actually unregistered.
    expect($writes)->toHaveCount(2);
    $firstData = collect($writes[0])->where('tool', 'data')->first();
    $secondData = collect($writes[1])->where('tool', 'data')->first();
    expect($firstData['instance'])->toBe('pocket-luchtech-dev')
        ->and($secondData['instance'])->toBe('main');
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
