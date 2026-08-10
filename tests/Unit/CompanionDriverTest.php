<?php

use App\Commands\Companion\CompanionAddCommand;
use App\Commands\Companion\CompanionRemoveCommand;
use App\Commands\Companion\CompanionStartCommand;
use App\Commands\Companion\CompanionStopCommand;
use App\Commands\UpCommand;
use App\Data\ConfigData;
use App\Enums\CompanionDriver;
use Illuminate\Support\Facades\View;

test('installable() returns all companions', function () {
    $installable = CompanionDriver::installable();

    expect(array_map(fn ($c) => $c->value, $installable))
        ->toContain('adminer')
        ->toContain('phpmyadmin')
        ->toContain('pgadmin')
        ->toContain('redisinsight')
        ->toContain('mongo-express');
});

test('every companion has a non-empty image', function () {
    foreach (CompanionDriver::cases() as $companion) {
        expect($companion->getImage())->not->toBeEmpty("image missing for {$companion->value}");
    }
});

test('every companion has a port greater than zero', function () {
    foreach (CompanionDriver::cases() as $companion) {
        expect($companion->getPort())->toBeGreaterThan(0, "port missing for {$companion->value}");
    }
});

test('phpMyAdmin env sets PMA_ARBITRARY to 1', function () {
    expect(CompanionDriver::PHPMYADMIN->getEnv()['PMA_ARBITRARY'])->toBe('1');
});

test('pgAdmin env has default credentials', function () {
    $env = CompanionDriver::PGADMIN->getEnv();

    expect($env)->toHaveKey('PGADMIN_DEFAULT_EMAIL')
        ->toHaveKey('PGADMIN_DEFAULT_PASSWORD');
});

test('companion:add command definition has companion argument', function () {
    $cmd = new CompanionAddCommand;

    expect($cmd->getDefinition()->hasArgument('companion'))->toBeTrue();
});

test('companion:remove command definition has optional companion argument and force option', function () {
    $cmd = new CompanionRemoveCommand;
    $argument = $cmd->getDefinition()->getArgument('companion');

    expect($cmd->getDefinition()->hasArgument('companion'))->toBeTrue()
        ->and($argument->isRequired())->toBeFalse()   // omit the slug → pick from installed
        ->and($cmd->getDefinition()->hasOption('force'))->toBeTrue();
});

test('companion:stop and companion:start have an optional companion argument', function () {
    foreach ([CompanionStopCommand::class, CompanionStartCommand::class] as $class) {
        $cmd = new $class;

        expect($cmd->getDefinition()->hasArgument('companion'))->toBeTrue("{$class} is missing the companion argument")
            ->and($cmd->getDefinition()->getArgument('companion')->isRequired())->toBeFalse("{$class} companion arg should be optional");
    }
});

test('companion commands only call CompanionDriver methods that actually exist', function () {
    // Regression guard for the `isDefault()` crash: both commands called a method
    // that was never implemented on the enum, and it only blew up at runtime with an
    // explicit slug (the tests never invoked handle(), so it slipped through). This
    // scans every $companion->foo()/$c->foo() call in the command sources and asserts
    // the method exists — catching undefined-method regressions without a cluster.
    $files = [
        app_path('Commands/Companion/CompanionAddCommand.php'),
        app_path('Commands/Companion/CompanionRemoveCommand.php'),
        app_path('Commands/Companion/CompanionStopCommand.php'),
        app_path('Commands/Companion/CompanionStartCommand.php'),
    ];

    foreach ($files as $file) {
        preg_match_all('/\$(?:companion|c)\s*\??->\s*([a-zA-Z_]+)\s*\(/', (string) file_get_contents($file), $matches);

        foreach (array_unique($matches[1]) as $method) {
            expect(method_exists(CompanionDriver::class, $method))
                ->toBeTrue("CompanionDriver::{$method}() is called in ".basename($file).' but does not exist');
        }
    }
});

test('Adminer connection hint uses server param for MySQL', function () {
    expect(CompanionDriver::ADMINER->getConnectionHint())
        ->toBe('mysql.{appname}.svc.cluster.local');
});

test('getUrl returns https with tld', function () {
    $url = CompanionDriver::PHPMYADMIN->getUrl();

    expect($url)->toStartWith('https://phpmyadmin.');
});

test('ManagesCompanions methods are callable on UpCommand', function () {
    $cmd = new UpCommand;

    expect(method_exists($cmd, 'refreshPhpMyAdminServers'))->toBeTrue()
        ->and(method_exists($cmd, 'showCompanionAccess'))->toBeTrue();
});

test('companion ingress renders on the explicitly passed TLD, not the shared boot value', function () {
    // Guards the config:tld staleness fix: View::share('localTld', …) is frozen at
    // provider boot, so when config:tld mutates the TLD and chains `up` in the same
    // process, the shared value is stale. deployCompanion() passes a freshly-loaded
    // localTld, which must override the share — here we simulate a stale share (kube)
    // and assert an explicit override (test) wins, so the ingress follows the new TLD.
    View::share('localTld', 'kube');

    $yaml = View::make('k8s.companion.global', [
        'companion' => CompanionDriver::PHPMYADMIN,
        'localTld' => 'test',
    ])->render();

    expect($yaml)->toContain('host: phpmyadmin.test')
        ->not->toContain('phpmyadmin.kube');
});

test('shared Mailpit blade template exists and contains larakube-shared namespace', function () {
    $content = file_get_contents(resource_path('views/k8s/mailpit/shared.blade.php'));

    expect($content)->toContain('larakube-shared')
        ->toContain('containerPort: 1025')
        ->toContain('containerPort: 8025')
        ->toContain('host: {{ $host }}');
});

test('recommendedFor() leads with pgAdmin then Adminer for a Postgres project', function () {
    $config = ConfigData::from(['name' => 'demo', 'database' => 'postgres']);

    $recommended = array_map(fn ($c) => $c->value, CompanionDriver::recommendedFor($config));

    expect($recommended)->toBe(['pgadmin', 'adminer']);
});

test('recommendedFor() leads with phpMyAdmin then Adminer for a MySQL project', function () {
    $config = ConfigData::from(['name' => 'demo', 'database' => 'mysql']);

    expect(array_map(fn ($c) => $c->value, CompanionDriver::recommendedFor($config)))
        ->toBe(['phpmyadmin', 'adminer']);
});

test('recommendedFor() adds RedisInsight when the project caches with Redis', function () {
    $config = ConfigData::from(['name' => 'demo', 'database' => 'postgres', 'cacheDriver' => 'redis']);

    expect(array_map(fn ($c) => $c->value, CompanionDriver::recommendedFor($config)))
        ->toBe(['pgadmin', 'adminer', 'redisinsight']);
});

test('recommendedFor() returns nothing for a SQLite-only project', function () {
    $config = ConfigData::from(['name' => 'demo', 'database' => 'sqlite']);

    expect(CompanionDriver::recommendedFor($config))->toBe([]);
});
