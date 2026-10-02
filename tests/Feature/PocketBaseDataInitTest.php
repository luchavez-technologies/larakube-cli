<?php

use App\Traits\InteractsWithToolRegistry;
use Illuminate\Support\Facades\Process;

pest()->use(InteractsWithToolRegistry::class);

beforeEach(function (): void {
    @unlink(getcwd().'/.larakube.local.json');
    @unlink(getcwd().'/.larakube.json');
});

test('pocketbase:init deploys pocketbase stack and creates pvc', function (): void {
    Process::fake([
        '*create namespace*' => Process::result(output: 'created'),
        '*get secret*' => Process::result(output: ''),
        '*apply*' => Process::result(output: 'created'),
        '*rollout status*' => Process::result(output: 'successfully rolled out'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('pocketbase:init local --admin-email=admin@example.com --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('Applying PocketBase manifests...')
        ->expectsOutputToContain('PocketBase Data / Headless CMS stack is live.');
});

test('directus:init uses engine label override when prompting for host', function (): void {
    Process::fake([
        '*create namespace*' => Process::result(output: 'created'),
        '*get secret*' => Process::result(output: ''),
        '*apply*' => Process::result(output: 'created'),
        '*rollout status*' => Process::result(output: 'successfully rolled out'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('pocketbase:init production --context=ctx --domain=pocket.luchtech.dev --admin-email=admin@example.com --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('Applying PocketBase manifests...')
        ->expectsOutputToContain('PocketBase Data / Headless CMS stack is live.');
});

test('directus:init deploys directus stack using commons postgres', function (): void {
    Process::fake([
        '*plex-commons*' => Process::result(output: '{"services":{"postgres":{"enabled":true},"redis":{"enabled":true},"seaweedfs":{"enabled":true}}}'),
        '*plex-registry*' => Process::result(output: '{"tenants":{}}'),
        '*plex-admin*' => Process::result(output: base64_encode('s3-access-key')),
        '*create namespace*' => Process::result(output: 'created'),
        // Real code base64_decode()s this — a raw non-base64 string here
        // silently decodes to garbage binary, which Illuminate 13's HTTP
        // client correctly rejects at the OpenBao push (json_encode() fails
        // on invalid UTF-8, previously swallowed silently pre-upgrade).
        '*get secret*' => Process::result(output: base64_encode('secret-val')),
        '*exec*' => Process::result(output: 'success'),
        '*apply*' => Process::result(output: 'created'),
        '*rollout status*' => Process::result(output: 'successfully rolled out'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('directus:init local --admin-email=admin@example.com --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('Applying Directus manifests...')
        ->expectsOutputToContain('Directus Data / Headless CMS stack is live.');
});

test('directus:init records which engine an instance runs in the cluster registry', function (): void {
    // Nothing about a Data instance's host or URL reveals which engine it
    // runs — directus:show/tool:list --json need this recorded, not just baked
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

    $this->artisan('pocketbase:init local --admin-email=admin@example.com --force')->assertExitCode(0);

    expect($captured)->not->toBeNull();
    $dataEntry = collect($captured)->first(fn ($e) => in_array($e['tool'] ?? null, ['data', 'pocketbase'], true));
    expect($dataEntry)->not->toBeNull()
        ->and($dataEntry['engine'])->toBe('pocketbase');
});

test('directus:init without --domain errors instead of guessing when an instance is already registered', function (): void {
    // directus:init now resolves host+instance via resolveInstanceAwareHost()
    // (the same pattern CRM/Design/Notes already use) instead of the old
    // split resolveToolHost()+resolveInstanceForDomain() two-step. That old
    // split is what let a plain re-run of directus:init silently derive the
    // wrong slug and duplicate-register (confirmed live 2026-08-09: DATA's
    // default host is pocket.luchtech.dev but the service hostPrefix is
    // 'data', so a no-flag re-run derived 'pocket-luchtech-dev', deployed a
    // SECOND PocketBase, and registered a duplicate row). The unified
    // resolver closes that class of bug at the root: whenever ANY instance
    // is already registered and no --domain is given, it refuses outright
    // rather than picking one — see ResolvesToolHost::resolveInstanceAwareHost()
    // and DesignInitCommandTest's equivalent guard.
    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(
            output: base64_encode((string) json_encode([
                ['tool' => 'data', 'instance' => 'main', 'aliases' => [], 'installedAt' => '2026-08-09T10:35:58+00:00', 'host' => 'pocket.luchtech.dev'],
            ])),
        ),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('pocketbase:init production --context=ctx --admin-email=admin@example.com --force --no-interaction')
        ->run();
})->throws(RuntimeException::class, 'pass --domain=<host>');

test('directus:init --domain re-targets an already-registered instance in place, never spawning a derived duplicate', function (): void {
    // Regression guard (confirmed live 2026-08-09): DATA's default host is
    // pocket.luchtech.dev but the service hostPrefix is 'data', so deriving
    // a slug from the host alone used to yield 'pocket-luchtech-dev' — a
    // DIFFERENT instance than the one already registered for that exact
    // host. Host identity must win: passing --domain of an already-registered
    // host updates that entry in place, never spawns a duplicate derived-slug
    // instance.
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

    $this->artisan('pocketbase:init production --context=ctx --domain=pocket.luchtech.dev --admin-email=admin@example.com --force --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('Applying PocketBase manifests...');

    // The manifest applied must reuse the already-registered 'main' instance
    // (now suffixed like any other instance, per ADR 0012's amendment), not
    // a fresh slug derived from the host.
    Process::assertRan(fn ($p) => str_contains((string) $p->command, 'pocketbase-main'))
        ->assertNotRan(fn ($p) => str_contains((string) $p->command, 'pocketbase-pocket-luchtech-dev'));

    $dataEntries = collect($captured ?? [])->filter(fn ($e) => in_array($e['tool'] ?? null, ['data', 'pocketbase'], true));
    expect($dataEntries)->toHaveCount(1)
        ->and($dataEntries->first()['instance'])->toBe('main')
        ->and($dataEntries->first()['host'])->toBe('pocket.luchtech.dev');
});

test('directus:init --domain resolves a distinct instance from the given host, not main\'s', function (): void {
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

    $this->artisan('pocketbase:init local --domain=blog.example.com --admin-email=admin@example.com --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('https://blog.example.com')
        ->doesntExpectOutputToContain('https://data.');
});

test('directus:init --alias registers an additional hostname on the same instance\'s Ingress', function (): void {
    Process::fake([
        '*create namespace*' => Process::result(output: 'created'),
        '*get secret*' => Process::result(output: ''),
        '*apply*' => Process::result(output: 'created'),
        '*rollout status*' => Process::result(output: 'successfully rolled out'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('pocketbase:init local --alias=alt.example.com --admin-email=admin@example.com --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('https://alt.example.com');
});

test('pocketbase:remove --domain derives the same instance directus:init would have, not main\'s', function (): void {
    // The --domain given here must resolve to the SAME instance identifier
    // (via ClusterTool::instanceSlugFromHost() — the full host, dashed, no
    // auto-prefixing) that directus:init would have derived from the identical
    // value, so removal always targets what you actually meant, not the
    // default instance.
    Process::fake([
        '*get deployment pocketbase-blog-example-com*' => Process::result(output: 'pocketbase-blog-example-com   1/1   1   1   10d'),
        '*get deployment directus-blog-example-com*' => Process::result(output: ''),
        '*delete*' => Process::result(output: 'deleted'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('pocketbase:remove local --domain=blog.example.com --force')->assertExitCode(0);

    Process::assertRan(fn ($process) => str_contains($process->command, 'delete')
        && str_contains($process->command, 'deployment/pocketbase-blog-example-com')
        && ! str_contains($process->command, 'secret/data-secrets '));
});

test('pocketbase:remove --domain removes EVERY instance registered for the host (duplicate cleanup)', function (): void {
    // Regression guard for the 2026-08-09 incident: the legacy un-suffixed
    // default instance (instance '') AND the buggy host-derived slug both
    // registered pocket.luchtech.dev. Removal means "take down everything
    // serving this host" — both instances must be torn down and
    // unregistered in one command, leaving a clean slate.
    $captured = null;
    $writes = [];

    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(
            output: base64_encode((string) json_encode([
                ['tool' => 'pocketbase', 'instance' => '', 'aliases' => [], 'installedAt' => '2026-08-09T10:35:58+00:00', 'host' => 'pocket.luchtech.dev'],
                ['tool' => 'pocketbase', 'instance' => 'pocket-luchtech-dev', 'aliases' => [], 'installedAt' => '2026-08-09T10:36:31+00:00', 'host' => 'pocket.luchtech.dev'],
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
        '*get deployment pocketbase*' => Process::result(output: 'pocketbase-pocket-luchtech-dev   1/1   1   1   10d'),
        '*get deployment directus*' => Process::result(output: ''),
        '*delete*' => Process::result(output: 'deleted'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('pocketbase:remove local --domain=pocket.luchtech.dev --force')->assertExitCode(0);

    Process::assertRan(fn ($process) => str_contains($process->command, 'delete')
        && str_contains($process->command, 'deployment/pocketbase-pocket-luchtech-dev'));

    // The loop ran ONE unregister per instance (two registry writes), each
    // dropping a DIFFERENT data entry — the fake re-seeds on every read, so
    // the second write still carries the other instance, which is expected;
    // what matters is both instances were actually unregistered.
    expect($writes)->toHaveCount(2);
    $firstData = collect($writes[0])->where('tool', 'pocketbase')->first();
    $secondData = collect($writes[1])->where('tool', 'pocketbase')->first();
    expect($firstData['instance'])->toBe('pocket-luchtech-dev')
        ->and($secondData['instance'])->toBe('');
});

test('pocketbase:remove removes pocketbase resources', function (): void {
    Process::fake([...registeredToolRemoveFakes('pocketbase:remove', 'tool-example-com'),
        '*delete*' => Process::result(output: 'deleted'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('pocketbase:remove local --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('Removing Data resources...');
});

test('pocketbase:remove tears down pocketbase\'s own Service and Ingress, not just Directus-shaped names', function (): void {
    // Regression guard for a live collision (2026-08-08): teardown() only
    // ever deleted service/data + ingress/data (Directus's actual names) and
    // service/data-{instance} + ingress/data-{instance} — never PocketBase's
    // real names (service/data-pocketbase, ingress/data-pocketbase-ingress).
    // Every past pocketbase:remove left those orphaned, and the next directus:init for
    // either engine collided with them on the shared Data host.
    Process::fake([...registeredToolRemoveFakes('pocketbase:remove', 'tool-example-com'),
        '*delete*' => Process::result(output: 'deleted'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('pocketbase:remove local --force')->assertExitCode(0);

    Process::assertRan(fn ($process) => str_contains($process->command, 'service/pocketbase-tool-example-com')
        && str_contains($process->command, 'ingress/pocketbase-tool-example-com-ingress')
        && str_contains($process->command, 'configmap/pocketbase-hooks-tool-example-com'));
});
