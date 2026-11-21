<?php

use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

test('erpnext:show --json returns the Administrator login for Desktop to reveal', function (): void {
    $names = ToolInstance::forHost(ClusterTool::ERPNEXT, 'erpnext.example.com');

    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(output: ''),
        '*get deployment -n larakube-shared *' => Process::result(output: $names->deployment()),
        "*{$names->secret()}*admin-password*" => Process::result(output: base64_encode('s3cret-pass')),
        '*' => Process::result(output: ''),
    ]);

    $exit = Artisan::call('erpnext:show production --domain=erpnext.example.com --context=orbstack --json');
    $payload = json_decode(trim(Artisan::output()), true);

    expect($exit)->toBe(0)
        ->and($payload['credentials'])->toBe([
            'admin_username' => 'Administrator',
            'admin_password' => 's3cret-pass',
        ]);
});
