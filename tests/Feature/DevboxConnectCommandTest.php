<?php

use App\Data\GlobalConfigData;
use App\Data\StackData;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/** Runs $test with a throwaway home folder that has one dev box and one deploy server registered. */
function withDevBoxConnectHome(callable $test): void
{
    $home = TemporaryDirectory::make()->deleteWhenDestroyed();
    $previous = getenv('HOME');
    putenv('HOME='.$home->path());
    $_SERVER['HOME'] = $home->path();

    $config = new GlobalConfigData;
    $config->putStack(new StackData(name: 'my-box', provider: 'gcp', ip: '203.0.113.50', sshKey: '/keys/box', role: 'dev'));
    $config->putStack(new StackData(name: 'prod', ip: '203.0.113.9', context: 'larakube-203.0.113.9', sshKey: '/keys/prod'));
    $config->save();

    try {
        $test($home->path());
    } finally {
        putenv($previous === false ? 'HOME' : 'HOME='.$previous);
        $_SERVER['HOME'] = $previous === false ? '' : $previous;
    }
}

function devBoxJson(): ?array
{
    $lines = preg_split('/\R/', trim(Artisan::output())) ?: [];

    return json_decode((string) end($lines), true);
}

/** A machine where the tunnel is (or is not) already running, and scp brings back the box's kubeconfig. */
function devBoxConnectFakes(?array &$commands, bool $tunnelAlive = false, bool $contextKnown = false): void
{
    $commands = [];
    $kubeconfig = "apiVersion: v1\nclusters:\n- cluster:\n    server: https://127.0.0.1:6443\n  name: k3s-larakube\ncontexts:\n- context:\n    cluster: k3s-larakube\n    user: k3s-larakube\n  name: k3s-larakube\ncurrent-context: k3s-larakube\nusers:\n- name: k3s-larakube\n  user:\n    token: abc\n";

    Process::fake(function ($process) use (&$commands, $tunnelAlive, $contextKnown, $kubeconfig) {
        $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
        $commands[] = $cmd;

        if (str_contains($cmd, '-O check')) {
            return Process::result(exitCode: $tunnelAlive ? 0 : 255);
        }

        if (str_contains($cmd, 'config get-contexts')) {
            return Process::result(output: $contextKnown ? "larakube-devbox-my-box\n" : '');
        }

        if (str_starts_with($cmd, 'scp ')) {
            $destination = (string) preg_replace('/^.* (\S+)$/', '$1', $cmd);
            File::put($destination, $kubeconfig);
        }

        return Process::result(output: '');
    });
}

test('devbox:connect opens an SSH tunnel to the box\'s cluster and gives this computer a context for it', function (): void {
    devBoxConnectFakes($commands);

    withDevBoxConnectHome(function () use (&$commands): void {
        $exit = Artisan::call('devbox:connect', ['--stack-name' => 'my-box', '--json' => true, '--no-interaction' => true]);
        $result = devBoxJson();

        expect($exit)->toBe(0)
            ->and($result)->toMatchArray(['success' => true, 'context' => 'larakube-devbox-my-box'])
            ->and($result['port'])->toBeGreaterThanOrEqual(16443);

        $all = implode("\n", $commands);
        expect($all)->toContain("-L 127.0.0.1:{$result['port']}:127.0.0.1:6443")
            ->toContain("'/keys/box'")
            ->toContain("'larakube@203.0.113.50'");

        $kubeconfig = (string) file_get_contents(home_path('.kube/config'));
        expect($kubeconfig)->toContain("https://127.0.0.1:{$result['port']}")
            ->toContain('name: larakube-devbox-my-box')
            ->not->toContain('k3s-larakube');

        $stack = GlobalConfigData::load()->findStack('my-box');
        expect($stack->tunnelPort)->toBe($result['port'])
            ->and($stack->context)->toBe('larakube-devbox-my-box');
    });
});

test('devbox:connect with the tunnel already running starts nothing new and keeps the same port', function (): void {
    devBoxConnectFakes($commands);

    withDevBoxConnectHome(function () use (&$commands): void {
        Artisan::call('devbox:connect', ['--stack-name' => 'my-box', '--json' => true, '--no-interaction' => true]);
        $first = devBoxJson();

        devBoxConnectFakes($commands, tunnelAlive: true, contextKnown: true);
        $exit = Artisan::call('devbox:connect', ['--stack-name' => 'my-box', '--json' => true, '--no-interaction' => true]);

        expect($exit)->toBe(0)
            ->and(devBoxJson()['port'])->toBe($first['port'])
            ->and(implode("\n", $commands))->not->toContain('ssh -f -N')
            ->not->toContain('scp ');
    });
});

test('devbox:connect refuses a server that is not a dev box, and says so in a form a program can read', function (): void {
    devBoxConnectFakes($commands);

    withDevBoxConnectHome(function () use (&$commands): void {
        foreach (['prod', 'nope'] as $name) {
            $exit = Artisan::call('devbox:connect', ['--stack-name' => $name, '--json' => true, '--no-interaction' => true]);

            expect($exit)->toBe(1)
                ->and(devBoxJson()['success'])->toBeFalse();
        }

        expect(implode("\n", $commands))->not->toContain('ssh -f -N');
    });
});

test('devbox:disconnect closes the tunnel and leaves the context for next time', function (): void {
    devBoxConnectFakes($commands);

    withDevBoxConnectHome(function () use (&$commands): void {
        Artisan::call('devbox:connect', ['--stack-name' => 'my-box', '--json' => true, '--no-interaction' => true]);
        $exit = Artisan::call('devbox:disconnect', ['--stack-name' => 'my-box', '--json' => true, '--no-interaction' => true]);

        expect($exit)->toBe(0)
            ->and(devBoxJson())->toBe(['success' => true, 'disconnected' => true])
            ->and(implode("\n", $commands))->toContain('-O exit')
            ->and(GlobalConfigData::load()->findStack('my-box')->context)->toBe('larakube-devbox-my-box');
    });
});
