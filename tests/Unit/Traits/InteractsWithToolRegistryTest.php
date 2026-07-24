<?php

use App\Enums\ClusterTool;
use App\Traits\InteractsWithToolRegistry;
use Illuminate\Support\Facades\Process;

test('getRegisteredTools parses valid JSON from cluster secret', function () {
    $trait = new class
    {
        use InteractsWithToolRegistry;

        public function get(string $kubectl)
        {
            return $this->getRegisteredTools($kubectl);
        }
    };

    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(
            base64_encode(json_encode(['sso' => ['installed_at' => 123]])),
        ),
    ]);

    $tools = $trait->get('kubectl');
    expect($tools)->toBeArray()
        ->and($tools['sso']['installed_at'])->toBe(123);
});

test('getRegisteredTools returns empty array when secret is missing', function () {
    $trait = new class
    {
        use InteractsWithToolRegistry;

        public function get(string $kubectl)
        {
            return $this->getRegisteredTools($kubectl);
        }
    };

    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(''),
    ]);

    expect($trait->get('kubectl'))->toBeArray()->toBeEmpty();
});

test('registerTool adds tool to registry and saves it', function () {
    $trait = new class
    {
        use InteractsWithToolRegistry;

        public function register(string $kubectl, ClusterTool $tool)
        {
            return $this->registerTool($kubectl, $tool, ['engine' => 'zitadel']);
        }
    };

    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(''),
        '*create namespace larakube-shared*' => Process::result(),
        '*create secret generic larakube-tools-registry*' => Process::result(),
    ]);

    expect($trait->register('kubectl', ClusterTool::SSO))->toBeTrue();

    Process::assertRan(function ($process) {
        return str_contains($process->command, 'create secret generic larakube-tools-registry') &&
               str_contains($process->command, 'zitadel');
    });
});

test('unregisterTool removes tool from registry and saves it', function () {
    $trait = new class
    {
        use InteractsWithToolRegistry;

        public function unregister(string $kubectl, ClusterTool $tool)
        {
            return $this->unregisterTool($kubectl, $tool);
        }
    };

    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(
            base64_encode(json_encode(['sso' => ['installed_at' => 123]])),
        ),
        '*create namespace larakube-shared*' => Process::result(),
        '*create secret generic larakube-tools-registry*' => Process::result(),
    ]);

    expect($trait->unregister('kubectl', ClusterTool::SSO))->toBeTrue();

    Process::assertRan(function ($process) {
        return str_contains($process->command, 'create secret generic larakube-tools-registry') &&
               str_contains($process->command, '[]');
    });
});
