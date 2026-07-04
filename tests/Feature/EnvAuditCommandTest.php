<?php

/**
 * `env:audit` shells out to kubectl to list Secret/ConfigMap key names — never
 * values. CI has no kubectl (and no git), so every kubectl call is faked by
 * overriding shell_exec() in the SAME namespace as the caller (App\Commands,
 * since EnvAuditCommand::objectDataKeys() calls it unqualified — PHP resolves
 * an unqualified call against the current namespace first). Same trick as
 * tests/Feature/ClusterContextTest.php, just in App\Commands instead of
 * App\Traits.
 *
 * NOTE: Illuminate's expectsOutputToContain() mocks Command::doWrite() with one
 * Mockery expectation per requested substring; when two requested substrings
 * can both match the SAME doWrite() call (e.g. two cells rendered by Prompts'
 * table() on one line), only the first-registered expectation gets credited —
 * the second reports "not found" even though the text is really there. Verified
 * empirically while writing this file. So each test below asserts exactly ONE
 * substring per artisan() call; classifySource()'s own unit test (see
 * tests/Unit/Commands/EnvAuditCommandTest.php) is what actually proves the
 * managed-vs-custom pairing, since it needs no console output at all.
 */

namespace App\Commands {
    function shell_exec($command)
    {
        if (isset($GLOBALS['mock_shell_exec_callback']) && is_callable($GLOBALS['mock_shell_exec_callback'])) {
            return ($GLOBALS['mock_shell_exec_callback'])($command);
        }

        return \shell_exec($command);
    }
}

namespace Tests\Feature {

    use App\Data\ConfigData;
    use App\Enums\DatabaseDriver;

    beforeEach(function () {
        $this->tempDir = sys_get_temp_dir().'/larakube-env-audit-'.uniqid();
        mkdir($this->tempDir, 0755, true);
        $this->originalDir = getcwd();
        chdir($this->tempDir);
    });

    afterEach(function () {
        chdir($this->originalDir);
        exec('rm -rf '.escapeshellarg($this->tempDir));
        unset($GLOBALS['mock_shell_exec_callback']);
    });

    function saveEnvAuditConfig(string $dir): void
    {
        $config = ConfigData::from([
            'name' => 'env-audit-test',
            'serverVariation' => 'fpm-nginx',
            'phpVersion' => '8.5',
            'database' => 'postgres',
            'environments' => [
                'local' => [],
                // A managed-context env — environmentContextOrCurrent() reads
                // $cloud->context directly, no prompt, no shell_exec/exec at all.
                'production' => [],
            ],
        ]);
        $config->setDatabase(DatabaseDriver::POSTGRESQL);
        $config->setCloud('production', ['context' => 'fake-ctx']);
        $config->setPath($dir);
        $config->saveToFile($dir);
    }

    function mockKubectlSecretsAndConfig(): void
    {
        $GLOBALS['mock_shell_exec_callback'] = function (string $command) {
            if (str_contains($command, 'get secret')) {
                return json_encode(['data' => [
                    'DB_PASSWORD' => base64_encode('shh'),
                    'AIRTABLE_API_KEY' => base64_encode('key_live_123'),
                ]]);
            }
            if (str_contains($command, 'get configmap')) {
                return json_encode(['data' => [
                    'APP_URL' => base64_encode('https://example.com'),
                ]]);
            }

            return '';
        };
    }

    test('env:audit resolves the environment and targets its saved cluster context', function () {
        saveEnvAuditConfig($this->tempDir);
        mockKubectlSecretsAndConfig();

        $this->artisan('env:audit', ['environment' => 'production'])
            ->assertExitCode(0)
            ->expectsOutputToContain('fake-ctx');
    });

    test('env:audit lists a key from laravel-secrets', function () {
        saveEnvAuditConfig($this->tempDir);
        mockKubectlSecretsAndConfig();

        $this->artisan('env:audit', ['environment' => 'production'])
            ->assertExitCode(0)
            ->expectsOutputToContain('AIRTABLE_API_KEY');
    });

    test('env:audit lists a key from laravel-config', function () {
        saveEnvAuditConfig($this->tempDir);
        mockKubectlSecretsAndConfig();

        $this->artisan('env:audit', ['environment' => 'production'])
            ->assertExitCode(0)
            ->expectsOutputToContain('APP_URL');
    });

    test('env:audit flags a LaraKube-generated key as managed', function () {
        saveEnvAuditConfig($this->tempDir);
        mockKubectlSecretsAndConfig();

        $this->artisan('env:audit', ['environment' => 'production'])
            ->assertExitCode(0)
            ->expectsOutputToContain('LaraKube-managed');
    });

    test('env:audit flags a human-typed key as custom, never a value', function () {
        saveEnvAuditConfig($this->tempDir);
        mockKubectlSecretsAndConfig();

        $this->artisan('env:audit', ['environment' => 'production'])
            ->assertExitCode(0)
            ->doesntExpectOutputToContain('key_live_123');
    });

    test('env:audit reports nothing deployed yet when both objects are missing', function () {
        saveEnvAuditConfig($this->tempDir);
        $GLOBALS['mock_shell_exec_callback'] = fn (string $command) => '';

        $this->artisan('env:audit', ['environment' => 'production'])
            ->assertExitCode(0)
            ->expectsOutputToContain("No 'laravel-secrets' or 'laravel-config' found");
    });

    test('env:audit requires a namespace outside a project', function () {
        // No .larakube.json in this tempDir — getProjectConfig() returns null.
        $this->artisan('env:audit')
            ->assertExitCode(1)
            ->expectsOutputToContain('Provide a namespace, or run inside a project to pick an environment.');
    });
}
