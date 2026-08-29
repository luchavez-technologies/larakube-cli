<?php

use App\Http\Integrations\OpenBao\Requests\DynamicNoBodyRequest;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Laravel\Facades\Saloon;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('monitor:init --no-logs deploys metrics-only stack without loki, promtail and tempo', function (): void {
    Process::fake([
        '*get configmap plex-commons*' => json_encode([
            'version' => 1,
            'services' => ['postgres' => ['enabled' => true]],
        ]),
        '*exec *' => Process::result(output: 'success'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*create configmap*' => Process::result(output: 'configmap created'),
        '*get secret*' => Process::result(output: '', exitCode: 1),
        '*get deployment*' => Process::result(output: '', exitCode: 1),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('monitor:init local --no-logs')
        ->assertExitCode(0)
        ->expectsOutputToContain('Waiting for Prometheus...')
        ->doesntExpectOutputToContain('Waiting for Loki...')
        ->expectsOutputToContain('Waiting for kube-state-metrics...')
        ->expectsOutputToContain('Waiting for Grafana...')
        ->doesntExpectOutputToContain('Waiting for Promtail...')
        ->doesntExpectOutputToContain('Waiting for Tempo...')
        ->expectsOutputToContain('Log aggregation (Loki + Promtail) is disabled (~300MB RAM saved).')
        ->expectsOutputToContain('Run larakube monitor:init --with-logs anytime to enable log search in Grafana.')
        ->expectsOutputToContain('Distributed tracing (Tempo) is disabled (~450MB RAM saved).')
        ->expectsOutputToContain('Run larakube monitor:init --with-traces anytime to enable trace search in Grafana.')
        ->expectsOutputToContain('Dashboards: Cluster Overview, Nodes, Pods.');

    Process::assertRan(fn ($p) => str_contains($p->command, 'create configmap grafana-dashboards'));
    Process::assertNotRan('*rollout restart*');
    Process::assertNotRan('*delete *');
});

test('monitor:init --with-logs deploys full stack including loki and promtail', function (): void {
    Process::fake([
        '*get configmap plex-commons*' => json_encode([
            'version' => 1,
            'services' => ['postgres' => ['enabled' => true]],
        ]),
        '*exec *' => Process::result(output: 'success'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*create configmap*' => Process::result(output: 'configmap created'),
        '*get secret*' => Process::result(output: '', exitCode: 1),
        '*get deployment*' => Process::result(output: '', exitCode: 1),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('monitor:init local --with-logs')
        ->assertExitCode(0)
        ->expectsOutputToContain('Waiting for Prometheus...')
        ->expectsOutputToContain('Waiting for Loki...')
        ->expectsOutputToContain('Waiting for kube-state-metrics...')
        ->expectsOutputToContain('Waiting for Grafana...')
        ->expectsOutputToContain('Waiting for Promtail...')
        ->doesntExpectOutputToContain('Waiting for Tempo...')
        ->expectsOutputToContain('Prometheus + Loki are pre-wired as Grafana data sources.')
        ->expectsOutputToContain('Dashboards: Cluster Overview, Nodes, Pods, Loki Logs.');

    Process::assertNotRan('*rollout restart*');
});

test('monitor:init --with-traces --with-logs deploys the full stack including tempo', function (): void {
    Process::fake([
        '*get configmap plex-commons*' => json_encode([
            'version' => 1,
            'services' => ['postgres' => ['enabled' => true]],
        ]),
        '*exec *' => Process::result(output: 'success'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*create configmap*' => Process::result(output: 'configmap created'),
        '*get secret*' => Process::result(output: '', exitCode: 1),
        '*get deployment*' => Process::result(output: '', exitCode: 1),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('monitor:init local --with-traces --with-logs')
        ->assertExitCode(0)
        ->expectsOutputToContain('Waiting for Loki...')
        ->expectsOutputToContain('Waiting for Promtail...')
        ->expectsOutputToContain('Waiting for Tempo...')
        ->expectsOutputToContain('Prometheus + Loki + Tempo are pre-wired as Grafana data sources.')
        ->expectsOutputToContain('Dashboards: Cluster Overview, Nodes, Pods, Loki Logs, Tempo Service Graph.');

    Process::assertNotRan('*rollout restart*');
    Process::assertNotRan('*delete *');
});

test('monitor:init defaults to metrics-only in non-interactive mode', function (): void {
    Process::fake([
        '*get configmap plex-commons*' => json_encode([
            'version' => 1,
            'services' => ['postgres' => ['enabled' => true]],
        ]),
        '*exec *' => Process::result(output: 'success'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*create configmap*' => Process::result(output: 'configmap created'),
        '*get secret*' => Process::result(output: '', exitCode: 1),
        '*get deployment*' => Process::result(output: '', exitCode: 1),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('monitor:init local --no-interaction')
        ->assertExitCode(0)
        ->doesntExpectOutputToContain('Waiting for Loki...')
        ->doesntExpectOutputToContain('Waiting for Promtail...')
        ->doesntExpectOutputToContain('Waiting for Tempo...')
        ->expectsOutputToContain('Log aggregation (Loki + Promtail) is disabled (~300MB RAM saved).')
        ->expectsOutputToContain('Distributed tracing (Tempo) is disabled (~450MB RAM saved).');
});

test('monitor:init --no-logs removes a previously deployed log aggregation stack and restarts grafana', function (): void {
    Process::fake([
        '*get configmap plex-commons*' => json_encode([
            'version' => 1,
            'services' => ['postgres' => ['enabled' => true]],
        ]),
        '*exec *' => Process::result(output: 'success'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*create configmap*' => Process::result(output: 'configmap created'),
        '*get secret*' => Process::result(output: '', exitCode: 1),
        '*get deployment/grafana*' => Process::result(output: 'grafana 1/1', exitCode: 0),
        '*get deployment/monitor-loki*' => Process::result(output: 'loki 1/1', exitCode: 0),
        '*get deployment/tempo*' => Process::result(output: '', exitCode: 1),
        '*get deployment*' => Process::result(output: '', exitCode: 1),
        '*delete deployment,svc,configmap,pvc monitor-loki*' => Process::result(output: 'deleted'),
        '*delete daemonset,configmap monitor-promtail*' => Process::result(output: 'deleted'),
        '*delete serviceaccount promtail*' => Process::result(output: 'deleted'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout restart*' => Process::result(output: 'restarted'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    // confirmComponentRemoval() short-circuits its confirm() prompt behind
    // `--force || --no-interaction || !stream_isatty(STDIN)` — the last
    // clause makes the prompt's very presence depend on whether the runner
    // itself has a real TTY (true under a real terminal or `script`-PTY,
    // false in a plain non-interactive runner), which would make this
    // test's expectations non-deterministic across environments. --force
    // pins it to the same bypass path every time; the removal itself (not
    // the confirmation UX) is what this test is about.
    $this->artisan('monitor:init local --no-logs --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('Removing Loki...')
        ->expectsOutputToContain('Removing Promtail...')
        ->expectsOutputToContain('Restarting Grafana to load the updated data sources...')
        ->expectsOutputToContain('Waiting for Grafana after restart...')
        ->expectsOutputToContain('Log aggregation (Loki + Promtail) removed');

    Process::assertRan(fn ($p) => str_contains($p->command, 'delete deployment,svc,configmap,pvc monitor-loki'));
    Process::assertRan(fn ($p) => str_contains($p->command, 'delete daemonset,configmap monitor-promtail'));
    Process::assertRan(fn ($p) => str_contains($p->command, 'rollout restart'));
    Process::assertNotRan('*delete deployment,svc,configmap,pvc tempo*');
});

test('monitor:init --no-traces removes a previously deployed tempo stack and restarts grafana', function (): void {
    Process::fake([
        '*get configmap plex-commons*' => json_encode([
            'version' => 1,
            'services' => ['postgres' => ['enabled' => true]],
        ]),
        '*exec *' => Process::result(output: 'success'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*create configmap*' => Process::result(output: 'configmap created'),
        '*get secret*' => Process::result(output: '', exitCode: 1),
        '*get deployment/grafana*' => Process::result(output: 'grafana 1/1', exitCode: 0),
        '*get deployment/monitor-loki*' => Process::result(output: '', exitCode: 1),
        '*get deployment/tempo*' => Process::result(output: 'tempo 1/1', exitCode: 0),
        '*get deployment*' => Process::result(output: '', exitCode: 1),
        '*delete deployment,svc,configmap,pvc tempo*' => Process::result(output: 'deleted'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout restart*' => Process::result(output: 'restarted'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    // See the --no-logs test above for why --force (not a scripted confirm)
    // is what keeps this deterministic across TTY/non-TTY runners.
    $this->artisan('monitor:init local --no-traces --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('Removing Tempo...')
        ->expectsOutputToContain('Restarting Grafana to load the updated data sources...')
        ->expectsOutputToContain('Tempo removed');

    Process::assertRan(fn ($p) => str_contains($p->command, 'delete deployment,svc,configmap,pvc tempo'));
    Process::assertRan(fn ($p) => str_contains($p->command, 'rollout restart'));
    Process::assertNotRan('*delete deployment,svc,configmap,pvc loki*');
});

test('monitor:init re-running with matching flags is a no-op — no deletions, no grafana restart', function (): void {
    Process::fake([
        '*get configmap plex-commons*' => json_encode([
            'version' => 1,
            'services' => ['postgres' => ['enabled' => true]],
        ]),
        '*exec *' => Process::result(output: 'success'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*create configmap*' => Process::result(output: 'configmap created'),
        '*get secret*' => Process::result(output: '', exitCode: 1),
        '*get deployment/grafana*' => Process::result(output: 'grafana 1/1', exitCode: 0),
        '*get deployment/monitor-loki*' => Process::result(output: 'loki 1/1', exitCode: 0),
        '*get deployment/tempo*' => Process::result(output: 'tempo 1/1', exitCode: 0),
        '*get deployment*' => Process::result(output: '', exitCode: 1),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('monitor:init local --with-logs --with-traces')
        ->assertExitCode(0)
        ->doesntExpectOutputToContain('Removing Loki...')
        ->doesntExpectOutputToContain('Removing Tempo...')
        ->doesntExpectOutputToContain('Restarting Grafana');

    Process::assertNotRan('*delete *');
    Process::assertNotRan('*rollout restart*');
});

test('monitor:init allocates a real Commons Postgres database for Grafana instead of leaving it on ephemeral SQLite', function (): void {
    // Previously monitor:init never touched Postgres at all — Grafana's own
    // database (UI-created dashboards, folders, alert rules, users) lived
    // only in its built-in SQLite on the pod's ephemeral filesystem, wiped
    // on every pod recreation. Confirmed live 2026-08-18 — a teammate's
    // dashboard work was lost this way. Dashboards-as-code (the JSON files
    // provisioned into the 'LaraKube' folder) were never affected by this.
    Process::fake([
        '*get configmap plex-commons*' => json_encode([
            'version' => 1,
            'services' => ['postgres' => ['enabled' => true]],
        ]),
        '*exec *' => Process::result(output: 'success'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*create configmap*' => Process::result(output: 'configmap created'),
        '*get secret*' => Process::result(output: '', exitCode: 1),
        '*get deployment*' => Process::result(output: '', exitCode: 1),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('monitor:init local --no-logs')
        ->assertExitCode(0)
        ->expectsOutputToContain("Allocating database 'grafana' in the Commons");

    Process::assertRan(fn ($p) => str_contains($p->command, 'exec -i -n')
        && str_contains($p->command, 'larakube-plex')
        && str_contains($p->command, 'deploy/postgres'));
});

test('monitor:init never registers an OpenBao static role itself — only secrets:wire may hand rotation over', function (): void {
    // Same design principle as GitInitCommandTest's sibling: {tool}:init
    // must not know or care whether OpenBao is installed — it just writes
    // the locally-generated password directly into monitor-secrets (see
    // the Deployment template's db-password key, rendered from the PHP
    // variable). Only secrets:wire may register a tool's DB password as an
    // OpenBao static role. This test previously asserted the OPPOSITE
    // (monitor:init reconciling monitor-secrets-db itself) — that assertion
    // encoded the exact bug this design principle exists to prevent.
    Process::fake([
        '*get configmap plex-commons*' => json_encode([
            'version' => 1,
            'services' => ['postgres' => ['enabled' => true]],
        ]),
        '*exec *' => Process::result(output: 'success'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*create configmap*' => Process::result(output: 'configmap created'),
        '*get secret openbao-bootstrap*' => Process::result(output: base64_encode('hvs.token')),
        '*get secret*' => Process::result(output: '', exitCode: 1),
        '*port-forward*' => Process::result(output: ''),
        '*get deployment*' => Process::result(output: '', exitCode: 1),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
        '*' => Process::result(),
    ]);

    // Only resolveManagedDbPassword()'s read-only lookup should ever hit
    // OpenBao's HTTP API from :init — nothing here is a static-role write.
    Saloon::fake([
        DynamicNoBodyRequest::class => openBaoFake([
            '*/v1/sys/mounts' => ['data' => ['database/' => ['type' => 'database']]],
            '*/v1/database/static-creds/grafana' => ['data' => []],
        ], default: ['data' => []]),
    ]);

    $this->artisan('monitor:init local --no-logs')
        ->assertExitCode(0);

    Process::assertNotRan(fn ($p) => str_contains($p->command, 'externalsecret'));
    Saloon::assertNotSent(fn ($request) => str_contains($request->resolveEndpoint(), '/v1/database/static-roles/'));
});

test('monitor:init --no-plex skips Commons Postgres entirely and uses a local PVC for SQLite instead', function (): void {
    // The fallback for a cluster with no Plex Commons at all — mirrors
    // git:init's own --no-plex story. Still genuinely persistent (a PVC
    // survives pod recreation, unlike the pre-fix ephemeral-only setup),
    // just not backed by Commons Postgres or its nightly backup.
    Process::fake([
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*create configmap*' => Process::result(output: 'configmap created'),
        '*get secret*' => Process::result(output: '', exitCode: 1),
        '*get deployment*' => Process::result(output: '', exitCode: 1),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('monitor:init local --no-logs --no-plex')
        ->assertExitCode(0)
        ->expectsOutputToContain('SQLite on a local PVC')
        ->doesntExpectOutputToContain("Allocating database 'grafana' in the Commons");

    Process::assertNotRan(fn ($p) => str_contains($p->command, 'exec')
        && str_contains($p->command, 'deploy/postgres'));
    Process::assertNotRan(fn ($p) => str_contains($p->command, 'get configmap plex-commons'));
});

test('monitoring shared blade view conditionally renders optional components based on withLogs and withTraces', function (): void {
    $metricsOnlyManifest = view('k8s.monitoring.shared', [
        'host' => 'grafana.dev.test',
        'instance' => 'grafana-dev-test',
        'grafanaPassword' => 'secret123',
        'dbPassword' => 'db-secret123',
        'plexNamespace' => 'larakube-plex',
        'isLocal' => true,
        'vpnOnly' => false,
        'withLogs' => false,
        'withTraces' => false,
    ])->render();

    expect($metricsOnlyManifest)->toContain('app: monitor-prometheus-grafana-dev-test')
        ->toContain('app: kube-state-metrics')
        ->toContain('app: monitor-grafana-grafana-dev-test')
        ->toContain('name: grafana-dashboard-provider')
        // Grafana's own DB must be Commons Postgres, not left on ephemeral
        // SQLite — see monitor:init's dedicated allocation test.
        ->toContain('GF_DATABASE_TYPE')
        ->toContain('value: postgres')
        ->toContain('db-password')
        ->not->toContain('app: monitor-loki')
        ->not->toContain('app: monitor-promtail')
        ->not->toContain('app: tempo')
        ->not->toContain('name: Loki')
        ->not->toContain('name: Tempo')
        ->not->toContain('metrics_generator');

    $fullManifest = view('k8s.monitoring.shared', [
        'host' => 'grafana.dev.test',
        'instance' => 'grafana-dev-test',
        'grafanaPassword' => 'secret123',
        'dbPassword' => 'db-secret123',
        'plexNamespace' => 'larakube-plex',
        'isLocal' => true,
        'vpnOnly' => false,
        'withLogs' => true,
        'withTraces' => true,
    ])->render();

    expect($fullManifest)->toContain('app: monitor-prometheus-grafana-dev-test')
        ->toContain('app: kube-state-metrics')
        ->toContain('app: monitor-grafana-grafana-dev-test')
        ->toContain('app: monitor-loki-grafana-dev-test')
        ->toContain('app: monitor-promtail-grafana-dev-test')
        ->toContain('app: tempo')
        ->toContain('name: Loki')
        ->toContain('name: Tempo')
        ->toContain('uid: loki-ds')
        ->toContain('uid: tempo-ds')
        ->toContain('metrics_generator')
        ->toContain('tempo-storage')
        ->toContain('grafana/tempo:2.10.7')
        ->toContain('mountPath: /var/lib/grafana/dashboards/');
});
