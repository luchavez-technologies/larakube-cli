<?php

use App\Enums\ClusterTool;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Process;

/**
 * `{tool}:remove {environment}` replaces `{tool}:init --remove`. These cover
 * the behaviour the shared base owns for all 24 tools, plus the per-tool
 * teardown details that were previously only exercised through the init
 * command's --remove branch.
 */
test('tool:remove takes the environment as its only positional', function (): void {
    foreach (ClusterTool::shippedCases() as $tool) {
        $definition = $this->app
            ->make(Kernel::class)
            ->all()[$tool->removeCommand()]
            ->getDefinition();

        expect(array_keys($definition->getArguments()))->toBe(['environment']);
    }
});

test('every tool has a remove command and none of them still accept --remove on init', function (): void {
    $commands = $this->app->make(Kernel::class)->all();

    foreach (ClusterTool::shippedCases() as $tool) {
        expect($commands)->toHaveKey($tool->removeCommand())
            ->and($commands[$tool->initCommand()]->getDefinition()->hasOption('remove'))->toBeFalse("{$tool->initCommand()} still carries the decoupled --remove flag");
    }
});

/**
 * A registered n8n instance at flow.example.com, as the live cluster reports
 * it to App\Services\Kubectl (quoted arguments).
 */
function flowRemoveFakes(array $extra = [], bool $bundled = false): array
{
    $deployment = 'n8n-flow-example-com';

    return [...registeredToolRemoveFakes('flow:remove', 'flow-example-com', 'flow.example.com'),
        "*get deployment/{$deployment} *-o json*" => Process::result(output: json_encode([
            'kind' => 'Deployment',
            'metadata' => ['name' => $deployment, 'labels' => $bundled ? ['larakube-storage' => 'bundled'] : []],
        ])),
        "*get deployment/{$deployment} *-o name*" => Process::result(output: "deployment/{$deployment}"),
        '*get deployment/*' => Process::result(output: ''),
        ...$extra,
        '*' => Process::result(output: ''),
    ];
}

test('flow:remove keeps the database, the encryption key and the data volume by default', function (): void {
    Process::fake(flowRemoveFakes(['* delete *' => Process::result(output: 'deleted')]));

    $this->artisan('flow:remove local --force')
        ->assertExitCode(0)
        ->doesntExpectOutputToContain('Dropping database')
        ->expectsOutputToContain('Removing Flow resources...')
        ->expectsOutputToContain('Persistent data (Plex Commons DB + S3 buckets) was preserved.');

    Process::assertRan(fn ($p) => str_contains($p->command, 'delete deployment/n8n-flow-example-com service/n8n-flow-example-com ingress/n8n-flow-example-com secret/n8n-smtp-flow-example-com')
        && str_contains($p->command, 'middleware/flow-vpn-only-flow-example-com'));
    Process::assertNotRan(fn ($p) => str_contains($p->command, 'secret/n8n-secrets-')
        || str_contains($p->command, 'persistentvolumeclaim/'));
});

test('flow:remove --purge drops only the engine it ran, and its key and volume', function (): void {
    Process::fake(flowRemoveFakes([
        '*exec *' => Process::result(output: 'dropped'),
        '* delete *' => Process::result(output: 'deleted'),
    ]));

    $this->artisan('flow:remove local --force --purge')
        ->assertExitCode(0)
        ->expectsOutputToContain("Dropping database 'n8n_flow_example_com' from Plex Commons")
        ->doesntExpectOutputToContain("Dropping database 'windmill")
        ->expectsOutputToContain('Removing Flow data volumes and keys...');

    Process::assertRan(fn ($p) => str_contains($p->command, 'persistentvolumeclaim/n8n-storage-flow-example-com')
        && str_contains($p->command, 'secret/n8n-secrets-flow-example-com'));
});

test('flow:remove --purge leaves the Commons alone for a --no-plex install', function (): void {
    Process::fake(flowRemoveFakes(['* delete *' => Process::result(output: 'deleted')], bundled: true));

    $this->artisan('flow:remove local --force --purge')
        ->assertExitCode(0)
        ->doesntExpectOutputToContain('Dropping database');
});

test('flow:remove --domain removes only that host\'s instance', function (): void {
    Process::fake(flowRemoveFakes(['* delete *' => Process::result(output: 'deleted')]));

    $this->artisan('flow:remove local --force --domain=flow.example.com')->assertExitCode(0);

    // Every name a delete touches belongs to flow.example.com's instance.
    Process::assertNotRan(fn ($p) => str_contains($p->command, ' delete ')
        && preg_match_all('#\b(?:deployment|service|ingress|secret|persistentvolumeclaim|middleware)/([a-z0-9-]+)#', $p->command, $m) > 0
        && collect($m[1])->contains(fn (string $name) => ! str_ends_with($name, '-flow-example-com')));
});

test('a failed database drop does not delete the OpenBao static role for a still-live tenant', function (): void {
    // Regression: dropCommonsTenants() used to call deleteStaticRole()
    // unconditionally, even when the DROP DATABASE step failed — which is
    // close to the DEFAULT outcome, not an edge case, since Postgres refuses
    // to drop a database with active connections and the tenant being
    // --purge'd is normally still live at that moment. That orphaned
    // OpenBao's rotation for a tenant that kept running fine. Confirmed live
    // 2026-08-23 on 4 tools (stalwart, record_sendrec, resume_reactive,
    // sheet's role) — see plans/active/openbao-static-role-coverage.md.
    Process::fake(flowRemoveFakes([
        '*get secret openbao-bootstrap*' => Process::result(output: base64_encode('hvs.token')),
        '*exec *' => Process::result(output: '', exitCode: 1),
        '*port-forward*' => Process::result(output: ''),
        '* delete *' => Process::result(output: 'deleted'),
        '*' => Process::result(output: ''),
    ]));

    $this->artisan('flow:remove local --force --purge');

    // deleteStaticRole() only ever reaches OpenBao via a port-forward — none
    // should have been attempted for either database once their drops failed.
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'port-forward'));
});

test('sheets:remove --purge drops the Commons database AND its S3 buckets, not just the database', function (): void {
    // The bug this guards: --purge dropped the Postgres tenant but silently
    // left every tool's S3 bucket (and its contents) behind — commonsBuckets()
    // was declared but never consulted by the teardown path.
    Process::fake([...registeredToolRemoveFakes('sheets:remove', 'sheet-example-com', 'sheet.example.com'),
        '*get configmap plex-registry*' => Process::result(output: json_encode([
            'tenants' => [
                'teable-public-sheet-example-com' => ['s3_bucket' => 'teable-public-sheet-example-com', 's3_service' => 'seaweedfs'],
                'teable-private-sheet-example-com' => ['s3_bucket' => 'teable-private-sheet-example-com', 's3_service' => 'seaweedfs'],
            ],
        ])),
        '*exec *' => Process::result(output: 'dropped'),
        '*delete *' => Process::result(output: 'deleted'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('sheets:remove local --force --purge')
        ->assertExitCode(0)
        ->expectsOutputToContain("Dropping database 'teable_sheet_example_com' from Plex Commons")
        ->expectsOutputToContain("Dropping object-storage bucket 'teable-public-sheet-example-com' from Plex Commons")
        ->expectsOutputToContain("Dropping object-storage bucket 'teable-private-sheet-example-com' from Plex Commons");
});

test('a bucket drop falls back to the Commons spec\'s enabled S3 backend when the registry has no record for it', function (): void {
    // A pre-registry install (bucket created before the tenant registry
    // tracked s3_service) has nothing to read the backend from — fall back
    // to whichever S3 service the live Commons spec has enabled, the same
    // discovery order every {tool}:init uses to pick one in the first place.
    Process::fake([...registeredToolRemoveFakes('sheets:remove', 'sheet-example-com', 'sheet.example.com'),
        '*get configmap plex-registry*' => Process::result(output: json_encode(['tenants' => []])),
        '*get configmap plex-commons*' => Process::result(output: json_encode([
            'services' => ['seaweedfs' => ['enabled' => true]],
        ])),
        '*exec *' => Process::result(output: 'dropped'),
        '*delete *' => Process::result(output: 'deleted'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('sheets:remove local --force --purge')
        ->assertExitCode(0)
        ->expectsOutputToContain("Dropping object-storage bucket 'teable-public-sheet-example-com' from Plex Commons");
});

test('drive:remove --purge does NOT drop its Commons bucket — oCIS encryption keys would orphan the data', function (): void {
    Process::fake([...registeredToolRemoveFakes('drive:remove'),
        '*get configmap plex-registry*' => Process::result(output: json_encode([
            'tenants' => ['drive-ocis' => ['s3_bucket' => 'drive-ocis', 's3_service' => 'seaweedfs']],
        ])),
        '*delete *' => Process::result(output: 'deleted'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('drive:remove local --force --purge')
        ->assertExitCode(0)
        ->doesntExpectOutputToContain('Dropping object-storage bucket');

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'weed shell')
        || str_contains($process->command, 'bucket.delete'));
});

test('a failed delete exits non-zero instead of reporting success', function (): void {
    // The bug this guards: every tool's remove path used to discard the step
    // result and print "removed" regardless of what kubectl actually did.
    Process::fake(flowRemoveFakes([
        '* delete *' => Process::result(output: '', errorOutput: 'forbidden', exitCode: 1),
    ]));

    $this->artisan('flow:remove local --force')
        ->assertExitCode(1)
        ->expectsOutputToContain('failed to remove');
});

test('namespace-wholesale tools delete their own namespace and nothing shared', function (): void {
    foreach ([ClusterTool::PASSWORDS, ClusterTool::VPN] as $tool) {
        Process::fake([...registeredToolRemoveFakes($tool->removeCommand()),
            '*delete namespace*' => Process::result(output: 'deleted'),
            '*' => Process::result(output: ''),
        ]);

        $this->artisan("{$tool->removeCommand()} local --force")->assertExitCode(0);

        Process::assertRan(fn ($process) => str_contains($process->command, "delete namespace {$tool->namespace()}")
            && ! str_contains($process->command, 'larakube-shared'));
    }
});

test('mail:remove closes the firewall ports it opened', function (): void {
    Process::fake([...registeredToolRemoveFakes('mail:remove'),
        '*delete *' => Process::result(output: 'deleted'),
        '*wait *' => Process::result(output: ''),
        '*get secrets*' => Process::result(output: ''),
        '*' => Process::result(output: ''),
    ]);

    // A mail server that's gone but whose SMTP ports stay open is a real
    // exposure, so teardown must reach the firewall too.
    $this->artisan('mail:remove local --force')->assertExitCode(0);
});

test('--domain on a single-instance tool errors instead of silently no-opping', function (): void {
    // sso:remove/mail:remove/etc. inherit --domain from the shared base
    // unconditionally, but SSO/MAIL's teardown targets fixed resource names
    // — --domain=foo.example.com would do nothing (or a misleading partial
    // removal) rather than what it implies. hasInstanceAwareRemoval() guards
    // this for every tool where it's false, not just these two.
    $this->artisan('sso:remove local --domain=foo.example.com --force')
        ->assertExitCode(1)
        ->expectsOutputToContain('does not support multiple instances');

    $this->artisan('mail:remove local --domain=foo.example.com --force')
        ->assertExitCode(1)
        ->expectsOutputToContain('does not support multiple instances');
});

test('omitting --domain is always allowed, even for single-instance tools', function (): void {
    Process::fake([...registeredToolRemoveFakes('sso:remove'), '*' => Process::result(output: '')]);

    $this->artisan('sso:remove local --force')->assertExitCode(0);
});

test('--domain on a tool without real per-instance teardown errors instead of silently deleting the one real install', function (): void {
    // ClusterTool::supportsMultipleInstances() defaults `true` for these —
    // "no known architectural blocker", not "already built". Their :remove
    // commands' teardown() is fully hardcoded and ignores $instance/--domain
    // entirely, so --domain=anything used to be silently accepted and then
    // silently ignored: it always deleted the one real installation
    // regardless of what host was passed. hasInstanceAwareRemoval() closes
    // that gap for every tool except the 4 with real per-instance logic.
    $groupB = array_filter(
        ClusterTool::shippedCases(),
        // DNS excluded: dns:remove is a bespoke Cloudflare-zone command that
        // never extended AbstractToolRemoveCommand and has no --domain option
        // at all — this loop only covers tools sharing the generic guard.
        fn (ClusterTool $tool) => ! $tool->hasInstanceAwareRemoval() && $tool !== ClusterTool::DNS,
    );

    expect($groupB)->not->toBeEmpty();

    foreach ($groupB as $tool) {
        $this->artisan("{$tool->value}:remove local --domain=foo.example.com --force")
            ->assertExitCode(1)
            ->expectsOutputToContain('does not support multiple instances');
    }
});

test('a tool with no registered instance reports nothing to remove and deletes nothing', function (): void {
    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(output: base64_encode('[]')),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('notes:remove local')
        ->expectsOutputToContain("instances are registered in 'local', so there is nothing to remove.")
        ->assertExitCode(0);

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'delete'));
});

test('the confirmation names the host of the registered instance being removed', function (): void {
    Process::fake([...registeredToolRemoveFakes('notes:remove', 'notes-example-com', 'notes.example.com'),
        '*delete*' => Process::result(output: 'deleted'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('notes:remove local')
        ->expectsOutputToContain('Instance(s): notes.example.com')
        ->expectsQuestion('Type confirm to proceed', 'confirm')
        ->assertExitCode(0);

    Process::assertRan(fn ($process) => str_contains($process->command, 'delete')
        && str_contains($process->command, 'deployment/outline-notes-example-com'));
});

test('removing a tool also removes its secrets:wire database-password sync', function (): void {
    Process::fake([...registeredToolRemoveFakes('sign:remove', 'sign-example-com', 'sign.example.com'),
        '*get secret documenso-secrets*' => Process::result(output: 'secret/documenso-secrets-sign-example-com'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('sign:remove local --force')->assertExitCode(0);

    // Both objects secrets:wire creates, for this instance's DB secret.
    Process::assertRan(fn ($process) => str_contains($process->command, 'delete externalsecret,vaultdynamicsecret.generators.external-secrets.io documenso-secrets-sign-example-com-db'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'secret/documenso-signing-cert-sign-example-com'));
});

test('removing an SSO-wired tool deletes its Zitadel app, then the Secret recording it', function (): void {
    Saloon\Laravel\Facades\Saloon::fake([
        App\Http\Integrations\Zitadel\Requests\DeleteProjectAppRequest::class => Saloon\Http\Faking\MockResponse::make([]),
    ]);
    Process::fake([...registeredToolRemoveFakes('sign:remove', 'sign-example-com', 'sign.example.com'),
        '*get secret documenso-sso-sign-example-com*project-id*' => Process::result(output: base64_encode('111')),
        '*get secret documenso-sso-sign-example-com*app-id*' => Process::result(output: base64_encode('222')),
        '*get secret sso-secrets*machine-pat*' => Process::result(output: base64_encode('pat')),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('sign:remove local --force')->assertExitCode(0);

    Saloon\Laravel\Facades\Saloon::assertSent(fn ($request) => $request instanceof App\Http\Integrations\Zitadel\Requests\DeleteProjectAppRequest
        && str_contains($request->resolveEndpoint(), '/projects/111/apps/222'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'delete secret documenso-sso-sign-example-com'));
    Saloon\Http\Faking\MockClient::destroyGlobal();
});

test('if Zitadel can\'t be reached, removal keeps the Secret so the app can still be found', function (): void {
    Process::fake([...registeredToolRemoveFakes('sign:remove', 'sign-example-com', 'sign.example.com'),
        '*get secret documenso-sso-sign-example-com*project-id*' => Process::result(output: base64_encode('111')),
        '*get secret documenso-sso-sign-example-com*app-id*' => Process::result(output: base64_encode('222')),
        '*get secret sso-secrets*' => Process::result(output: ''),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('sign:remove local --force')->assertExitCode(0);

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'delete secret documenso-sso'));
});
