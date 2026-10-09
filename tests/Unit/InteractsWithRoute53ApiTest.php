<?php

use App\Traits\InteractsWithRoute53Api;
use Illuminate\Support\Facades\Process;

function route53ApiHarness(): object
{
    return new class
    {
        use InteractsWithRoute53Api;

        public function available(): bool
        {
            return $this->route53Available();
        }

        public function env(array $credential): array
        {
            return $this->route53Env($credential);
        }

        public function listZones(array $env): array
        {
            return $this->route53ListZones($env);
        }

        public function canWriteDns(array $env, string $zoneId, string $zone): bool
        {
            return $this->route53CanWriteDns($env, $zoneId, $zone);
        }
    };
}

test('route53Available reflects whether the aws CLI is installed', function (): void {
    Process::fake(['*command -v aws*' => Process::result(output: '/usr/local/bin/aws')]);
    expect(route53ApiHarness()->available())->toBeTrue();

    Process::fake(['*command -v aws*' => Process::result(output: '', exitCode: 1)]);
    expect(route53ApiHarness()->available())->toBeFalse();
});

test('route53Env builds the aws CLI env vars from a credential bundle, defaulting the region', function (): void {
    $env = route53ApiHarness()->env(['access_key_id' => 'AKIAFAKE', 'secret_access_key' => 'shh']);

    expect($env)->toBe([
        'AWS_ACCESS_KEY_ID' => 'AKIAFAKE',
        'AWS_SECRET_ACCESS_KEY' => 'shh',
        'AWS_DEFAULT_REGION' => 'us-east-1',
    ]);
});

test('route53ListZones returns every hosted zone, stripping the hostedzone/ prefix and trailing dot', function (): void {
    Process::fake([
        '*route53 list-hosted-zones*' => Process::result(output: (string) json_encode([
            'HostedZones' => [
                ['Id' => '/hostedzone/Z111', 'Name' => 'ourfridays.com.'],
                ['Id' => '/hostedzone/Z222', 'Name' => 'larakube.app.'],
            ],
        ])),
    ]);

    expect(route53ApiHarness()->listZones(['AWS_ACCESS_KEY_ID' => 'k']))->toBe([
        'Z111' => 'ourfridays.com',
        'Z222' => 'larakube.app',
    ]);
});

test('route53ListZones returns an empty array when the aws call fails', function (): void {
    Process::fake(['*route53 list-hosted-zones*' => Process::result(output: '', exitCode: 1)]);

    expect(route53ApiHarness()->listZones(['AWS_ACCESS_KEY_ID' => 'k']))->toBe([]);
});

test('route53CanWriteDns creates then deletes a probe TXT record', function (): void {
    $calls = [];
    Process::fake([
        '*route53 change-resource-record-sets*' => function ($process) use (&$calls) {
            $calls[] = $process->command;

            return Process::result(output: (string) json_encode(['ChangeInfo' => ['Id' => 'C1', 'Status' => 'PENDING']]));
        },
    ]);

    expect(route53ApiHarness()->canWriteDns(['AWS_ACCESS_KEY_ID' => 'k'], 'Z123', 'example.com'))->toBeTrue()
        ->and($calls)->toHaveCount(2);
});

test('route53CanWriteDns returns false when the create change fails, without attempting a delete', function (): void {
    $calls = [];
    Process::fake([
        '*route53 change-resource-record-sets*' => function ($process) use (&$calls) {
            $calls[] = $process->command;

            return Process::result(output: '', exitCode: 1);
        },
    ]);

    expect(route53ApiHarness()->canWriteDns(['AWS_ACCESS_KEY_ID' => 'k'], 'Z123', 'example.com'))->toBeFalse()
        ->and($calls)->toHaveCount(1);
});
