<?php

use Illuminate\Support\Facades\Process;

function uptimeInProject(array $config, callable $fn): void
{
    $dir = sys_get_temp_dir().'/uptime-'.uniqid();
    mkdir($dir, 0755, true);
    file_put_contents($dir.'/.larakube.json', json_encode($config + ['name' => 'demo']));

    $original = getcwd();
    chdir($dir);

    try {
        $fn($dir);
    } finally {
        chdir($original);
        exec('rm -rf '.escapeshellarg($dir));
    }
}

test('uptime:show warns and exits 1 if uptime kuma is not installed', function () {
    Process::fake([
        'kubectl get deployment uptime-kuma -n larakube-shared*' => Process::result(output: '', exitCode: 1),
    ]);

    $this->artisan('uptime:show local')
        ->assertExitCode(1)
        ->expectsOutputToContain('Uptime Kuma is not installed in larakube-shared.');
});

test('uptime:show displays the recommended monitors guide when installed', function () {
    Process::fake([
        'kubectl get deployment uptime-kuma -n larakube-shared*' => 'uptime-kuma   1/1   1   1   5d',
    ]);

    $config = [
        'environments' => [
            'local' => [
                'hosts' => [
                    'web' => 'demo.dev.test',
                ],
            ],
        ],
    ];

    uptimeInProject($config, function () {
        $tld = App\Data\GlobalConfigData::load()->getLocalTld();
        $expectedHost = "demo.{$tld}";

        $this->artisan('uptime:show local')
            ->assertExitCode(0)
            ->expectsOutputToContain('Uptime Kuma')
            ->expectsOutputToContain('RECOMMENDED MONITORS')
            ->expectsOutputToContain($expectedHost)
            ->expectsOutputToContain('CONFIGURING ALERTS');
    });
});
