<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

test('bulwark:show --json includes the webmail admin password, not just the host', function (): void {
    // afterTable() only renders this to the human table — --json skipped it
    // entirely until credentials() was added, the same gap PocketBase/WordPress/
    // Directus had.
    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(output: ''),
        '*get deployment webmail*' => Process::result(output: ''),
        '*get deployment -n larakube-shared *' => Process::result(output: 'bulwark-mail-test'),
        '*WEBMAIL_ADMIN_PASSWORD*' => Process::result(output: base64_encode('s3cret-pass')),
        '*' => Process::result(output: ''),
    ]);

    $exit = Artisan::call('bulwark:show local --domain=mail.test --context=orbstack --json');
    $payload = json_decode(trim(Artisan::output()), true);

    expect($exit)->toBe(0)
        ->and($payload['credentials'])->toBe(['admin_password' => 's3cret-pass']);
});

test('bulwark:show --json reports no credentials when the secret has none yet', function (): void {
    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(output: ''),
        '*get deployment -n larakube-shared *' => Process::result(output: 'bulwark-mail-test'),
        '*' => Process::result(output: ''),
    ]);

    $exit = Artisan::call('bulwark:show local --domain=mail.test --context=orbstack --json');
    $payload = json_decode(trim(Artisan::output()), true);

    expect($exit)->toBe(0)
        ->and($payload['credentials'])->toBeNull();
});
