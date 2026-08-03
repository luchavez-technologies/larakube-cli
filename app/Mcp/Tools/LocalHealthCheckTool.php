<?php

namespace App\Mcp\Tools;

use App\Data\GlobalConfigData;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Process;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;

#[Name('local-health-check')]
#[Title('Local Health Check')]
#[Description('Verifies the status of the local orchestration environment (Docker, Kubernetes, Networking).')]
class LocalHealthCheckTool extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $report = ['### 🩺 LaraKube Local Health Report'];

        // 1. Check Docker
        if (Process::run('docker info')->successful()) {
            $report[] = '- ✅ **Docker:** Engine is running.';
        } else {
            $report[] = '- ❌ **Docker:** Engine is NOT running or not accessible.';
        }

        // 2. Check Kubernetes
        if (Process::run('kubectl get nodes')->successful()) {
            $report[] = '- ✅ **Kubernetes:** Cluster is reachable via kubectl.';
        } else {
            $report[] = "- ❌ **Kubernetes:** Cluster is NOT reachable. Try 'larakube cluster:setup'.";
        }

        // 3. Check Traefik
        $tld = GlobalConfigData::load()->getLocalTld();
        $traefikOk = Process::run('curl -sk --connect-timeout 2 https://console.'.$tld)->successful();
        if ($traefikOk) {
            $report[] = "- ✅ **Networking:** Traefik ingress is routing console.{$tld}.";
        } else {
            $report[] = "- ⚠️ **Networking:** Local domains (.{$tld}) might not be resolved or Traefik is down.";
        }

        return Response::text(implode("\n", $report));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
