<?php

use App\Data\ConfigData;
use Illuminate\Support\Facades\Process;

function cleanupTestDir(string $dir): void
{
    foreach (array_merge(glob($dir.'/*') ?: [], glob($dir.'/.*') ?: []) as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    rmdir($dir);
}

function plexJoinCommand(): object
{
    $command = new class extends App\Commands\Plex\PlexJoinCommand
    {
        public function callWireTenantDbSecret(string $kubectl, string $namespace, string $tenant): bool
        {
            return $this->wireTenantDbSecret($kubectl, $namespace, $tenant);
        }

        public function callRegisterStaticRole(string $kubectl, string $roleName, string $dbConfig, string $username): bool
        {
            return $this->registerStaticRole($kubectl, $roleName, $dbConfig, $username, '168h');
        }

        public function callWriteTenantConfig(
            string $projectPath,
            ConfigData $config,
            string $env,
            string $tenant,
            string $password,
            ?int $redisIndex,
            array $services,
            ?array $s3,
            ?array $search,
            bool $dbHandledByOpenBao,
        ): void {
            $this->writeTenantConfig($projectPath, $config, $env, $tenant, $password, $redisIndex, $services, $s3, $search, $dbHandledByOpenBao);
        }
    };

    $input = new Symfony\Component\Console\Input\ArrayInput([]);
    $input->bind($command->getDefinition());
    $command->setInput($input);
    $command->setOutput(new Illuminate\Console\OutputStyle($input, new Symfony\Component\Console\Output\BufferedOutput));

    return $command;
}

test('wireTenantDbSecret applies the manifest, waits for a fresh sync, and restarts an already-running app', function () {
    Process::fake([
        '*apply -f *' => Process::result(output: 'applied'),
        '*get deployment web*' => Process::result(output: 'web'),
        '*rollout restart*' => Process::result(output: 'restarted'),
        '*].status}*' => Process::result(output: 'True'),
        '*].reason}*' => Process::result(output: 'SecretSynced'),
        '*refreshTime}*' => Process::sequence([
            Process::result(output: ''),
            Process::result(output: '2026-07-31T00:00:00Z'),
        ]),
        '*' => Process::result(),
    ]);

    expect(plexJoinCommand()->callWireTenantDbSecret('kubectl', 'app-production', 'app_production'))->toBeTrue();

    Process::assertRan(fn ($process) => str_contains($process->command, 'apply -f'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'rollout restart deployment/web -n app-production'));

    // Regression guard for the false-negative found live 2026-08-01: ESO
    // doesn't watch the VaultDynamicSecret generators an ExternalSecret
    // references, so re-applying an unchanged ExternalSecret whose generator
    // just changed underneath it (e.g. a corrected OpenBao role path) sat
    // unsynced past waitForExternalSecretSynced()'s 30s window — genuinely
    // fine data, reported to the user as a failure. wireTenantDbSecret must
    // nudge ESO to reconcile immediately after applying, not just wait.
    Process::assertRan(fn ($process) => str_contains($process->command, 'annotate externalsecret laravel-secrets-db')
        && str_contains($process->command, 'app-production')
        && str_contains($process->command, 'force-sync='));
});

test('wireTenantDbSecret does not restart when the app has never been deployed', function () {
    Process::fake([
        '*apply -f *' => Process::result(output: 'applied'),
        '*get deployment web*' => Process::result(output: '', exitCode: 1),
        '*].status}*' => Process::result(output: 'True'),
        '*].reason}*' => Process::result(output: 'SecretSynced'),
        '*refreshTime}*' => Process::sequence([
            Process::result(output: ''),
            Process::result(output: '2026-07-31T00:00:00Z'),
        ]),
        '*' => Process::result(),
    ]);

    expect(plexJoinCommand()->callWireTenantDbSecret('kubectl', 'app-production', 'app_production'))->toBeTrue();

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'rollout restart'));
});

test('wireTenantDbSecret returns false and never restarts when the manifest apply fails', function () {
    Process::fake([
        '*apply -f *' => Process::result(output: '', exitCode: 1),
        '*' => Process::result(),
    ]);

    expect(plexJoinCommand()->callWireTenantDbSecret('kubectl', 'app-production', 'app_production'))->toBeFalse();

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'rollout restart'));
});

test('wireTenantDbSecret returns false and never restarts when the sync never goes fresh', function () {
    Process::fake([
        '*apply -f *' => Process::result(output: 'applied'),
        // Stuck: refreshTime never moves past "before".
        '*].status}*' => Process::result(output: 'True'),
        '*].reason}*' => Process::result(output: 'SecretSynced'),
        '*refreshTime}*' => Process::result(output: '2026-07-30T22:40:49Z'),
        '*' => Process::result(),
    ]);

    expect(plexJoinCommand()->callWireTenantDbSecret('kubectl', 'app-production', 'app_production'))->toBeFalse();

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'rollout restart'));
})->skip('exercises the full 30s default timeout — covered fast by SecretsWireCommandTest\'s isolated waitForExternalSecretSynced test instead, since the logic is now shared via the trait');

test('handle() wires registerStaticRole and wireTenantDbSecret to the SAME OpenBao role name', function () {
    // Regression guard for the real bug found live 2026-08-01: handle() called
    // registerStaticRole($kubectl, 'tenant-'.$tenant, ...) to CREATE the OpenBao
    // static role, but wireTenantDbSecret($kubectl, $targetNs, $tenant) — the
    // bare tenant, not $roleName ('tenant-'.$tenant), the name the role was
    // actually registered under — to read it back. Every OpenBao-backed
    // plex:join db wiring 400'd with "unknown role" and silently fell back to
    // the weaker .env-only mode. Calling the two methods with matching
    // arguments (as done below) can't catch a wiring bug in handle() itself —
    // it would pass whether or not handle() actually threads $roleName through
    // consistently — so that source-level check is separate, right after this.
    $tenant = 'luchtech_local';
    $roleName = 'tenant-'.$tenant;

    $appliedManifest = null;
    $refreshTimeCalls = 0;

    Process::fake(function ($process) use (&$appliedManifest, &$refreshTimeCalls) {
        if (str_contains($process->command, 'apply -f')) {
            preg_match('/apply -f (\'[^\']*\'|"[^"]*"|\S+)/', $process->command, $m);
            $path = trim($m[1] ?? '', '\'"');
            if ($path !== '' && file_exists($path)) {
                $appliedManifest = file_get_contents($path);
            }

            return Process::result(output: 'applied');
        }

        return match (true) {
            str_contains($process->command, 'get secret openbao-bootstrap') => Process::result(output: base64_encode('s.test-token')),
            str_contains($process->command, 'port-forward') => Process::result(output: ''),
            str_contains($process->command, 'get deployment web') => Process::result(output: '', exitCode: 1),
            str_contains($process->command, '].status}') => Process::result(output: 'True'),
            str_contains($process->command, '].reason}') => Process::result(output: 'SecretSynced'),
            // Before-apply read must differ from the during-poll read, or
            // waitForExternalSecretSynced treats it as a stale leftover sync
            // and polls until timeout — same "before" vs "now" pattern as the
            // wireTenantDbSecret tests above.
            str_contains($process->command, 'refreshTime}') => Process::result(
                output: (++$refreshTimeCalls === 1) ? '' : '2026-07-31T00:00:00Z',
            ),
            default => Process::result(),
        };
    });

    $registrationUrl = null;
    Http::fake(function ($request) use (&$registrationUrl) {
        if ($request->method() === 'POST' && str_contains($request->url(), '/database/static-roles/')) {
            $registrationUrl = $request->url();
        }

        return Http::response([], 204);
    });

    $command = plexJoinCommand();

    expect($command->callRegisterStaticRole('kubectl', $roleName, 'plex-postgres', $tenant))->toBeTrue();
    expect($command->callWireTenantDbSecret('kubectl', 'luchtech-local', $roleName))->toBeTrue();

    expect($registrationUrl)->not->toBeNull()
        ->and($registrationUrl)->toEndWith('/database/static-roles/'.$roleName);

    expect($appliedManifest)->not->toBeNull()
        ->and($appliedManifest)->toContain('database/static-creds/'.$roleName);
});

test('handle() passes registerStaticRole\'s $roleName, not the bare $tenant, to wireTenantDbSecret', function () {
    // The actual regression guard: registerStaticRole() registers under
    // 'tenant-'.$tenant ($roleName), so wireTenantDbSecret() — which reads
    // that same role back — must be called with $roleName too, not $tenant.
    // A full handle() run needs a huge fixture surface (project config,
    // registry, multiselect prompts, allocateDatabase, …) for no extra
    // safety over reading the one line that wires them together — this is
    // the same source-consistency style already used by PlexContextWiringTest.
    $src = file_get_contents(base_path('app/Commands/Plex/PlexJoinCommand.php'));

    expect($src)->toContain('$roleName = \'tenant-\'.$tenant;')
        ->and($src)->toContain('registerStaticRole($kubectl, $roleName, $dbConfig, $tenant, \'168h\')')
        ->and($src)->toContain('wireTenantDbSecret($kubectl, $targetNs, $roleName)')
        ->and($src)->not->toContain('wireTenantDbSecret($kubectl, $targetNs, $tenant)');
});

test('plex:join has no --rotate flag and never force-rotates a credential', function () {
    // plex:join used to have a --rotate flag that both (a) let you re-run the
    // join to add a service to an already-joined tenant, and (b) forced a
    // credential reset as a side effect — a flag on a JOIN command for
    // "actually reset the password" was exactly the kind of hidden, distinct
    // operation this CLI avoids elsewhere. Removed 2026-08-01: re-running
    // plex:join is now always safe (registerStaticRole's idempotent no-op is
    // itself the "safe to re-run" guarantee); resetting a credential is
    // plex:rotate's job, exclusively.
    $src = file_get_contents(base_path('app/Commands/Plex/PlexJoinCommand.php'));

    expect($src)->not->toContain('{--rotate') // the actual signature declaration
        ->and($src)->not->toContain("option('rotate')")
        ->and($src)->not->toContain('$this->rotateStaticRole(') // no CALL to it — a comment pointing at plex:rotate is fine
        ->and($src)->toContain('registerStaticRole($kubectl, $roleName, $dbConfig, $tenant, \'168h\')')
        ->and($src)->toContain('wireTenantDbSecret($kubectl, $targetNs, $roleName)');
});

test('writeTenantConfig omits DB_PASSWORD from the env file when OpenBao already owns it', function () {
    $dir = sys_get_temp_dir().'/larakube-plexjoin-test-'.uniqid();
    mkdir($dir);

    try {
        $config = ConfigData::from(['name' => 'demo']);

        plexJoinCommand()->callWriteTenantConfig(
            $dir, $config, 'production', 'demo_production', 'super-secret-pw',
            null, ['postgres'], null, null, true,
        );

        $written = file_get_contents($dir.'/.env.production');
        expect($written)->not->toContain('super-secret-pw');
        expect($written)->not->toContain('DB_PASSWORD=');
        expect($written)->toContain('DB_HOST=');
    } finally {
        cleanupTestDir($dir);
    }
});

test('writeTenantConfig strips a PRE-EXISTING stale DB_PASSWORD line, not just skips writing a new one', function () {
    // Regression guard: a tenant that joined BEFORE it was OpenBao-managed
    // already has DB_PASSWORD=<old value> sitting in .env. Omitting the key
    // from what's written left that stale line untouched forever — plex:show
    // then displayed a password that didn't match the real (OpenBao-rotated)
    // one. Caught live 2026-08-02 via a user screenshot.
    $dir = sys_get_temp_dir().'/larakube-plexjoin-test-'.uniqid();
    mkdir($dir);

    try {
        $config = ConfigData::from(['name' => 'demo']);
        file_put_contents($dir.'/.env.production', "APP_NAME=Demo\nDB_PASSWORD=stale-old-password\n");

        plexJoinCommand()->callWriteTenantConfig(
            $dir, $config, 'production', 'demo_production', 'super-secret-pw',
            null, ['postgres'], null, null, true,
        );

        $written = file_get_contents($dir.'/.env.production');
        expect($written)->not->toContain('stale-old-password')
            ->and($written)->not->toContain('DB_PASSWORD=')
            ->and($written)->toContain('APP_NAME=Demo');
    } finally {
        cleanupTestDir($dir);
    }
});

test('writeTenantConfig writes DB_PASSWORD normally when OpenBao is not involved', function () {
    $dir = sys_get_temp_dir().'/larakube-plexjoin-test-'.uniqid();
    mkdir($dir);

    try {
        $config = ConfigData::from(['name' => 'demo']);

        plexJoinCommand()->callWriteTenantConfig(
            $dir, $config, 'production', 'demo_production', 'super-secret-pw',
            null, ['postgres'], null, null, false,
        );

        $written = file_get_contents($dir.'/.env.production');
        expect($written)->toContain('DB_PASSWORD=super-secret-pw');
    } finally {
        cleanupTestDir($dir);
    }
});
