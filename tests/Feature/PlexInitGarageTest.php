<?php

use App\Commands\Plex\PlexInitCommand;
use App\Data\ConfigData;
use App\Enums\StorageDriver;
use App\Services\Kubectl;
use App\Services\PlexService;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

function makePlexInitProbe(): object
{
    return new class extends PlexInitCommand
    {
        public function __construct()
        {
            parent::__construct();
            $this->input = new ArrayInput([]);
            $this->output = new Illuminate\Console\OutputStyle($this->input, new BufferedOutput);
        }

        public function callEnsureCommonsSecret(array $enabled = []): void
        {
            $this->ensureCommonsSecret($enabled);
        }

        public function callEnsureGarageBootstrap(string $ns, string $kubectl, ?array $preImportGarageKey = null): void
        {
            $this->ensureGarageBootstrap($ns, $kubectl, $preImportGarageKey);
        }
    };
}

test('ensureCommonsSecret omits placeholder larakube S3 keys for Garage and generates GARAGE_RPC_SECRET', function (): void {
    $capturedApplyJson = null;

    Process::fake([
        '*get secret plex-admin -n larakube-plex -o name*' => Process::result(output: ''),
        '*apply -f -*' => function ($process) use (&$capturedApplyJson) {
            $capturedApplyJson = json_decode((string) $process->input, true);

            return Process::result(output: 'secret/plex-admin configured');
        },
        '*' => Process::result(output: ''),
    ]);

    makePlexInitProbe()->callEnsureCommonsSecret(['postgres', 'redis', 'garage']);

    expect($capturedApplyJson)->toBeArray()
        ->and($capturedApplyJson['data'])->toHaveKey('GARAGE_RPC_SECRET')
        ->and(base64_decode((string) $capturedApplyJson['data']['GARAGE_RPC_SECRET']))->toHaveLength(64)
        ->and($capturedApplyJson['data'])->not->toHaveKey('S3_ACCESS_KEY')
        ->and($capturedApplyJson['data'])->not->toHaveKey('S3_SECRET_KEY');
});

test('ensureCommonsSecret generates placeholder S3 keys only when SeaweedFS is enabled and preserves existing GARAGE_RPC_SECRET', function (): void {
    $capturedPatchJson = null;

    Process::fake([
        '*get secret plex-admin -n larakube-plex -o name*' => Process::result(output: 'secret/plex-admin'),
        '*{.data.POSTGRES_PASSWORD}*' => Process::result(output: base64_encode('pg-pass')),
        '*{.data.MYSQL_ROOT_PASSWORD}*' => Process::result(output: base64_encode('my-pass')),
        '*{.data.MEILI_MASTER_KEY}*' => Process::result(output: base64_encode('meili-key')),
        '*{.data.GARAGE_RPC_SECRET}*' => Process::result(output: base64_encode('existing-rpc-secret')),
        '*{.data.S3_ACCESS_KEY}*' => Process::result(output: ''),
        '*{.data.S3_SECRET_KEY}*' => Process::result(output: ''),
        '*patch secret plex-admin*' => function ($process) use (&$capturedPatchJson) {
            $capturedPatchJson = json_decode((string) $process->input, true);

            return Process::result(output: 'secret/plex-admin patched');
        },
        '*' => Process::result(output: ''),
    ]);

    makePlexInitProbe()->callEnsureCommonsSecret(['postgres', 'redis', 'seaweedfs']);

    expect($capturedPatchJson)->toBeArray()
        ->and($capturedPatchJson['data'])->not->toHaveKey('GARAGE_RPC_SECRET')
        ->and(base64_decode((string) $capturedPatchJson['data']['S3_ACCESS_KEY']))->toBe('larakube')
        ->and($capturedPatchJson['data'])->toHaveKey('S3_SECRET_KEY');
});

test('ensureGarageBootstrap uses direct argv without sh -c and performs U4 crash-repair via --show-secret', function (): void {
    $capturedPatch = null;

    Process::fake([
        '*exec -n larakube-plex deploy/garage -- /garage bucket list*' => Process::result(output: ''),
        '*exec -n larakube-plex deploy/garage -- /garage key list*' => Process::result(output: "GKexisting123  commons-admin\n"),
        '*get secret plex-admin -n larakube-plex -o jsonpath={.data.S3_ACCESS_KEY}*' => Process::result(output: ''),
        '*get secret plex-admin -n larakube-plex -o jsonpath={.data.S3_SECRET_KEY}*' => Process::result(output: ''),
        '*exec -n larakube-plex deploy/garage -- /garage key info commons-admin --show-secret*' => Process::result(
            output: "Key ID: GKrecovered999\nKey name: commons-admin\nSecret key: secretrecovered888\n",
        ),
        '*patch secret plex-admin*' => function ($process) use (&$capturedPatch) {
            $capturedPatch = json_decode((string) $process->input, true);

            return Process::result(output: 'secret/plex-admin patched');
        },
        '*' => Process::result(output: ''),
    ]);

    makePlexInitProbe()->callEnsureGarageBootstrap('larakube-plex', 'kubectl');

    Process::assertNotRan(fn ($p) => str_contains((string) $p->command, 'sh -c'));
    expect($capturedPatch['data']['S3_ACCESS_KEY'] ?? null)->toBe(base64_encode('GKrecovered999'))
        ->and($capturedPatch['data']['S3_SECRET_KEY'] ?? null)->toBe(base64_encode('secretrecovered888'));
});

test('ensureGarageBootstrap pre-imports commons-admin key before checking key list (T1)', function (): void {
    $capturedPatch = null;

    Process::fake([
        '*exec -n larakube-plex deploy/garage -- /garage bucket list*' => Process::result(output: ''),
        '*exec -n larakube-plex deploy/garage -- /garage key list*' => Process::result(output: "ID  Name\n"),
        '*exec -n larakube-plex deploy/garage -- /garage key import --yes GKorig111 secretorig222 -n commons-admin*' => Process::result(output: 'Key imported'),
        '*get secret plex-admin -n larakube-plex -o jsonpath={.data.S3_ACCESS_KEY}*' => Process::result(output: ''),
        '*get secret plex-admin -n larakube-plex -o jsonpath={.data.S3_SECRET_KEY}*' => Process::result(output: ''),
        '*patch secret plex-admin*' => function ($process) use (&$capturedPatch) {
            $capturedPatch = json_decode((string) $process->input, true);

            return Process::result(output: 'secret/plex-admin patched');
        },
        '*' => Process::result(output: ''),
    ]);

    makePlexInitProbe()->callEnsureGarageBootstrap('larakube-plex', 'kubectl', [
        'access' => 'GKorig111',
        'secret' => 'secretorig222',
    ]);

    Process::assertRan(fn ($p) => str_contains((string) $p->command, '/garage key import --yes GKorig111 secretorig222 -n commons-admin'));
    Process::assertNotRan(fn ($p) => str_contains((string) $p->command, '/garage key create'));
    Process::assertNotRan(fn ($p) => str_contains((string) $p->command, 'sh -c'));
    expect($capturedPatch['data']['S3_ACCESS_KEY'] ?? null)->toBe(base64_encode('GKorig111'))
        ->and($capturedPatch['data']['S3_SECRET_KEY'] ?? null)->toBe(base64_encode('secretorig222'));
});

test('PlexService::createBucket for Garage is shell-less, idempotent, and grants backup-reader when present', function (): void {
    Process::fake([
        '*exec -n larakube-plex deploy/garage -- /garage bucket info app-five*' => Process::result(output: '', exitCode: 1),
        '*exec -n larakube-plex deploy/garage -- /garage bucket create app-five*' => Process::result(output: 'Bucket created'),
        '*exec -n larakube-plex deploy/garage -- /garage bucket allow --read --write app-five --key commons-admin*' => Process::result(output: 'Allowed'),
        '*exec -n larakube-plex deploy/garage -- /garage key list*' => Process::result(output: "GK1 commons-admin\nGK2 backup-reader\n"),
        '*exec -n larakube-plex deploy/garage -- /garage bucket allow --read app-five --key backup-reader*' => Process::result(output: 'Allowed'),
        '*' => Process::result(output: ''),
    ]);

    $result = (new PlexService('orbstack'))->createBucket(StorageDriver::GARAGE, 'app-five');

    expect($result->successful())->toBeTrue();
    Process::assertNotRan(fn ($p) => str_contains((string) $p->command, 'sh -c'));
    Process::assertRan(fn ($p) => str_contains((string) $p->command, '/garage bucket create app-five'));
    Process::assertRan(fn ($p) => str_contains((string) $p->command, '/garage bucket allow --read --write app-five --key commons-admin'));
    Process::assertRan(fn ($p) => str_contains((string) $p->command, '/garage bucket allow --read app-five --key backup-reader'));
});

test('PlexService::createBucket skips create when Garage bucket already exists', function (): void {
    Process::fake([
        '*exec -n larakube-plex deploy/garage -- /garage bucket info app-five*' => Process::result(output: 'Bucket: app-five'),
        '*exec -n larakube-plex deploy/garage -- /garage bucket allow --read --write app-five --key commons-admin*' => Process::result(output: 'Allowed'),
        '*exec -n larakube-plex deploy/garage -- /garage key list*' => Process::result(output: "GK1 commons-admin\n"),
        '*' => Process::result(output: ''),
    ]);

    $result = (new PlexService('orbstack'))->createBucket(StorageDriver::GARAGE, 'app-five');

    expect($result->successful())->toBeTrue();
    Process::assertNotRan(fn ($p) => str_contains((string) $p->command, '/garage bucket create'));
    Process::assertNotRan(fn ($p) => str_contains((string) $p->command, '--key backup-reader'));
});

test('ensureStandaloneGarageSecret creates garage-rpc-secret idempotently only when needed', function (): void {
    $applied = [];

    Process::fake([
        '*get secret garage-rpc-secret -n demo-production -o jsonpath={.data.GARAGE_RPC_SECRET}*' => Process::result(output: ''),
        '*apply -f -*' => function ($process) use (&$applied) {
            $applied[] = json_decode((string) $process->input, true);

            return Process::result(output: 'secret/garage-rpc-secret configured');
        },
        '*' => Process::result(output: ''),
    ]);

    $config = new ConfigData(name: 'demo');
    $config->addObjectStorage(StorageDriver::GARAGE);

    StorageDriver::ensureStandaloneGarageSecret(Kubectl::current(), $config, 'demo-production', 'production');

    expect($applied)->toHaveCount(1)
        ->and($applied[0]['metadata']['name'])->toBe('garage-rpc-secret')
        ->and(base64_decode((string) $applied[0]['data']['GARAGE_RPC_SECRET']))->toHaveLength(64);
});

test('Forgejo template preserves its minio S3 driver strings per V2', function (): void {
    $blade = (string) file_get_contents(resource_path('views/k8s/forgejo/forgejo.blade.php'));

    expect($blade)
        ->toContain('value: minio')
        ->toContain('FORGEJO__storage__MINIO_');
});
