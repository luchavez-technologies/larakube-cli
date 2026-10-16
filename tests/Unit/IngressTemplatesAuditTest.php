<?php

use App\Enums\ClusterTool;

test('every cluster tool with an ingress template renders cleanly in isolation with reapplyToolIngress parameters', function (): void {
    $failed = [];

    foreach (ClusterTool::cases() as $tool) {
        $view = "k8s.{$tool->value}.ingress";
        if (! view()->exists($view)) {
            // Also check legacyCategoryPrefix if any
            $prefix = $tool->legacyCategoryPrefix();
            if ($prefix !== null && view()->exists("k8s.{$prefix}.ingress")) {
                $view = "k8s.{$prefix}.ingress";
            } else {
                continue;
            }
        }

        $instance = 'test-audit-instance';
        $deploymentName = $tool->deploymentName($instance);

        try {
            $rendered = view($view, [
                'host' => 'audit.example.com',
                'aliasHosts' => ['alias.example.com'],
                'instance' => $instance,
                'serviceName' => $deploymentName,
                'deployName' => $deploymentName,
                'deploymentName' => $deploymentName,
                'ingressName' => "{$tool->value}-{$instance}",
                'namespace' => $tool->namespace(),
                'isLocal' => false,
                'vpnOnly' => false,
                'proxied' => false,
            ])->render();

            expect($rendered)->toBeString()
                ->and(strlen($rendered))->toBeGreaterThan(20);
        } catch (Throwable $e) {
            $failed[$tool->value] = $e->getMessage();
        }
    }

    expect($failed)->toBeEmpty();
});
