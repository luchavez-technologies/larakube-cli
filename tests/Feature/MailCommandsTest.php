<?php

use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;

test('mail:accounts is registered', function () {
    $this->artisan('list')
        ->assertExitCode(0)
        ->expectsOutputToContain('mail:accounts');
});

test('mail:accounts shows error when stalwart not installed', function () {
    Process::fake(['*get statefulset stalwart*' => Process::result(output: '', exitCode: 1)]);

    $this->artisan('mail:accounts')
        ->assertExitCode(1)
        ->expectsOutputToContain('Stalwart is not installed');
});

test('mail:accounts shows empty when no accounts exist', function () {
    Process::fake([
        '*get statefulset stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*' => Process::result(output: '{"methodResponses":[["x:Account/query",{"ids":[],"queryState":"n"},"c0"],["x:Account/get",{"list":[],"notFound":[]},"c1"]],"sessionState":"x"}'),
    ]);

    $this->artisan('mail:accounts')
        ->assertExitCode(0)
        ->expectsOutputToContain('No accounts found');
});

test('mail:accounts lists accounts', function () {
    $callCount = 0;
    Process::fake([
        '*get statefulset stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*' => function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return Process::result(output: '{"methodResponses":[["x:Account/query",{"ids":["b","c"]},"c0"],["x:Account/get",{"list":[],"notFound":[]},"c1"]],"sessionState":"x"}');
            }

            return Process::result(output: '{"methodResponses":[["x:Account/get",{"list":[{"id":"b","name":"admin","description":"System administrator","emailAddress":"admin@example.com","roles":{"@type":"Admin"},"quotas":{},"usedDiskQuota":486},{"id":"c","name":"alice","description":"Alice Smith","emailAddress":"alice@example.com","roles":{"@type":"User"},"quotas":{"maxDiskQuota":1073741824},"usedDiskQuota":1048576}],"notFound":[]},"c1"]],"sessionState":"x"}');
        },
    ]);

    $exitCode = Illuminate\Support\Facades\Artisan::call('mail:accounts');
    $output = Illuminate\Support\Facades\Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('admin@example.com');
    expect($output)->toContain('alice@example.com');
});

test('mail:create is registered', function () {
    $this->artisan('list')
        ->assertExitCode(0)
        ->expectsOutputToContain('mail:create');
});

test('mail:create shows error when no domains configured', function () {
    Process::fake([
        '*get statefulset stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*' => Process::result(output: '{"methodResponses":[["x:Domain/query",{"ids":[]},"c0"],["x:Domain/get",{"list":[],"notFound":[]},"c1"]],"sessionState":"x"}'),
    ]);

    $this->artisan('mail:create')
        ->assertExitCode(1)
        ->expectsOutputToContain('No domains are configured');
});

test('mail:create creates account with given args', function () {
    $callCount = 0;
    Process::fake([
        '*get statefulset stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*' => function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return Process::result(output: '{"methodResponses":[["x:Domain/query",{"ids":["b"]},"c0"],["x:Domain/get",{"list":[],"notFound":[]},"c1"]],"sessionState":"x"}');
            }
            if ($callCount === 2) {
                return Process::result(output: '{"methodResponses":[["x:Domain/get",{"list":[{"id":"b","name":"example.com"}],"notFound":[]},"c1"]],"sessionState":"x"}');
            }

            return Process::result(output: '{"methodResponses":[["x:Account/set",{"created":{"new1":{"id":"d"}}},"c1"]],"sessionState":"x"}');
        },
    ]);

    $this->artisan('mail:create', [
        'email' => 'bob@example.com',
        '--name' => 'Bob Smith',
        '--password' => 'Str0ngP@ssw0rd!',
        '--quota' => 5,
    ])
        ->assertExitCode(0)
        ->expectsOutputToContain('bob@example.com')
        ->expectsOutputToContain('Str0ngP@ssw0rd!');
});

test('mail:delete is registered', function () {
    $this->artisan('list')
        ->assertExitCode(0)
        ->expectsOutputToContain('mail:delete');
});

test('mail:delete deletes account by email', function () {
    $callCount = 0;
    Process::fake([
        '*get statefulset stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*' => function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return Process::result(output: '{"methodResponses":[["x:Account/query",{"ids":["b"]},"c0"],["x:Account/get",{"list":[],"notFound":[]},"c1"]],"sessionState":"x"}');
            }
            if ($callCount === 2) {
                return Process::result(output: '{"methodResponses":[["x:Account/get",{"list":[{"id":"b","name":"admin","description":"Admin","emailAddress":"admin@example.com"}],"notFound":[]},"c1"]],"sessionState":"x"}');
            }

            return Process::result(output: '{"methodResponses":[["x:Account/set",{"destroyed":["b"]},"c1"]],"sessionState":"x"}');
        },
    ]);

    $exitCode = Illuminate\Support\Facades\Artisan::call('mail:delete', ['email' => 'admin@example.com', '--force' => true]);

    expect($exitCode)->toBe(0);
});

test('mail:password is registered', function () {
    $this->artisan('list')
        ->assertExitCode(0)
        ->expectsOutputToContain('mail:password');
});

test('mail:password resets password', function () {
    $callCount = 0;
    Process::fake([
        '*get statefulset stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*' => function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return Process::result(output: '{"methodResponses":[["x:Account/query",{"ids":["c"]},"c0"],["x:Account/get",{"list":[],"notFound":[]},"c1"]],"sessionState":"x"}');
            }
            if ($callCount === 2) {
                return Process::result(output: '{"methodResponses":[["x:Account/get",{"list":[{"id":"c","name":"alice","description":"Alice","emailAddress":"alice@example.com"}],"notFound":[]},"c1"]],"sessionState":"x"}');
            }

            return Process::result(output: '{"methodResponses":[["x:Account/set",{"updated":{"c":null}},"c1"]],"sessionState":"x"}');
        },
    ]);

    $this->artisan('mail:password', ['email' => 'alice@example.com', '--password' => 'NewStr0ngP@ss!', '--force' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('alice@example.com')
        ->expectsOutputToContain('NewStr0ngP@ss!');
});

test('mail:password without --force asks for confirmation and cancels on decline', function () {
    $callCount = 0;
    Process::fake([
        '*get statefulset stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*' => function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return Process::result(output: '{"methodResponses":[["x:Account/query",{"ids":["c"]},"c0"],["x:Account/get",{"list":[],"notFound":[]},"c1"]],"sessionState":"x"}');
            }

            return Process::result(output: '{"methodResponses":[["x:Account/get",{"list":[{"id":"c","name":"alice","description":"Alice","emailAddress":"alice@example.com"}],"notFound":[]},"c1"]],"sessionState":"x"}');
        },
    ]);

    Prompt::fake([Key::ENTER]); // accept confirm()'s default, which is No

    $command = app(App\Commands\Mail\MailPasswordCommand::class);
    $input = new Symfony\Component\Console\Input\ArrayInput(['email' => 'alice@example.com', '--password' => 'Whatever!123']);
    $input->bind($command->getDefinition());
    $input->setInteractive(true);
    $output = new Symfony\Component\Console\Output\BufferedOutput;
    $command->setInput($input);
    $command->setOutput(new Illuminate\Console\OutputStyle($input, $output));

    $exitCode = $command->handle();

    expect($exitCode)->toBe(0);
    expect($output->fetch())->toContain('invalidates');
    // Declining the confirmation must stop before the actual update call —
    // only the account-lookup JMAP calls (query + get) should have fired.
    expect($callCount)->toBe(2);
});

test('mail:quota is registered', function () {
    $this->artisan('list')
        ->assertExitCode(0)
        ->expectsOutputToContain('mail:quota');
});

test('mail:quota sets quota', function () {
    $callCount = 0;
    Process::fake([
        '*get statefulset stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*' => function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return Process::result(output: '{"methodResponses":[["x:Account/query",{"ids":["c"]},"c0"],["x:Account/get",{"list":[],"notFound":[]},"c1"]],"sessionState":"x"}');
            }
            if ($callCount === 2) {
                return Process::result(output: '{"methodResponses":[["x:Account/get",{"list":[{"id":"c","name":"alice","description":"Alice","emailAddress":"alice@example.com","quotas":{"maxDiskQuota":1073741824}}],"notFound":[]},"c1"]],"sessionState":"x"}');
            }

            return Process::result(output: '{"methodResponses":[["x:Account/set",{"updated":{"c":null}},"c1"]],"sessionState":"x"}');
        },
    ]);

    $exitCode = Illuminate\Support\Facades\Artisan::call('mail:quota', ['email' => 'alice@example.com', '--quota' => '10']);

    expect($exitCode)->toBe(0);
});

test('mail:show is registered', function () {
    $this->artisan('list')
        ->assertExitCode(0)
        ->expectsOutputToContain('mail:show');
});

test('mail:show displays admin credentials', function () {
    Process::fake([
        '*get statefulset stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('s3cret-p@ss')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
    ]);

    $this->artisan('mail:show')
        ->assertExitCode(0)
        ->expectsOutputToContain('admin')
        ->expectsOutputToContain('s3cret-p@ss');
});

test('mail:show <email> displays that account\'s client setup, never a password', function () {
    $callCount = 0;
    Process::fake([
        '*get statefulset stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*' => function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return Process::result(output: '{"methodResponses":[["x:Account/query",{"ids":["c"]},"c0"],["x:Account/get",{"list":[],"notFound":[]},"c1"]],"sessionState":"x"}');
            }

            return Process::result(output: '{"methodResponses":[["x:Account/get",{"list":[{"id":"c","name":"alice","description":"Alice Smith","emailAddress":"alice@example.com","roles":{"@type":"User"}}],"notFound":[]},"c1"]],"sessionState":"x"}');
        },
    ]);

    $this->artisan('mail:show', ['email' => 'alice@example.com'])
        ->assertExitCode(0)
        ->expectsOutputToContain('alice@example.com')
        ->expectsOutputToContain('Alice Smith')
        ->expectsOutputToContain('Issue a new one')
        ->doesntExpectOutputToContain('test-admin-pass');
});

test('mail:show <email> errors when the account does not exist', function () {
    Process::fake([
        '*get statefulset stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*' => Process::result(output: '{"methodResponses":[["x:Account/query",{"ids":[]},"c0"],["x:Account/get",{"list":[],"notFound":[]},"c1"]],"sessionState":"x"}'),
    ]);

    $this->artisan('mail:show', ['email' => 'ghost@example.com'])
        ->assertExitCode(1)
        ->expectsOutputToContain("Account 'ghost@example.com' not found");
});

test('mail:domains is registered', function () {
    $this->artisan('list')
        ->assertExitCode(0)
        ->expectsOutputToContain('mail:domains');
});

test('mail:domains shows empty when no domains exist', function () {
    Process::fake([
        '*get statefulset stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('test-admin-pass')),
        '*get pod -l app=stalwart*' => Process::result(output: 'pod/stalwart-0'),
        '*' => Process::result(output: '{"methodResponses":[["x:Domain/query",{"ids":[]},"c0"],["x:Domain/get",{"list":[],"notFound":[]},"c1"]],"sessionState":"x"}'),
    ]);

    $this->artisan('mail:domains')
        ->assertExitCode(0)
        ->expectsOutputToContain('No domains configured');
});

test('mail:show requires installed stalwart', function () {
    Process::fake(['*get statefulset stalwart*' => Process::result(output: '', exitCode: 1)]);

    $this->artisan('mail:show')
        ->assertExitCode(1)
        ->expectsOutputToContain('Stalwart is not installed');
});

test('mail:domains requires installed stalwart', function () {
    Process::fake(['*get statefulset stalwart*' => Process::result(output: '', exitCode: 1)]);

    $this->artisan('mail:domains')
        ->assertExitCode(1)
        ->expectsOutputToContain('Stalwart is not installed');
});

test('mail:create requires installed stalwart', function () {
    Process::fake(['*get statefulset stalwart*' => Process::result(output: '', exitCode: 1)]);

    $this->artisan('mail:create')
        ->assertExitCode(1)
        ->expectsOutputToContain('Stalwart is not installed');
});

test('mail:delete requires installed stalwart', function () {
    Process::fake(['*get statefulset stalwart*' => Process::result(output: '', exitCode: 1)]);

    $this->artisan('mail:delete')
        ->assertExitCode(1)
        ->expectsOutputToContain('Stalwart is not installed');
});

test('mail:password requires installed stalwart', function () {
    Process::fake(['*get statefulset stalwart*' => Process::result(output: '', exitCode: 1)]);

    $this->artisan('mail:password')
        ->assertExitCode(1)
        ->expectsOutputToContain('Stalwart is not installed');
});

test('mail:quota requires installed stalwart', function () {
    Process::fake(['*get statefulset stalwart*' => Process::result(output: '', exitCode: 1)]);

    $this->artisan('mail:quota')
        ->assertExitCode(1)
        ->expectsOutputToContain('Stalwart is not installed');
});
