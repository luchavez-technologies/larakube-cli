<?php

namespace App\Commands\Grafana;

use App\Commands\Monitor\MonitorInitCommand;
use App\Enums\ClusterTool;

class GrafanaInitCommand extends MonitorInitCommand
{
    protected $signature = 'grafana:init
        {environment? : Environment this install targets — "local" (default) or a cloud env. Omit to be prompted, like plex:init. A non-local env prompts for + persists the Grafana host.}
        {--context=   : Target a specific kube-context (defaults to current context)}
        {--domain=    : Base domain OR full host for Grafana (example.com → grafana.example.com; grafana.example.com used as-is)}
        {--app-name=  : Custom branding name for Grafana (defaults to Monitor)}
        {--logo-url=  : Custom logo / favicon URL for Grafana}
        {--vpn-only   : Restrict access via NetBird VPN IP whitelisting}
        {--no-logs    : Skip deploying Loki + Promtail log aggregation (~300MB RAM saved)}
        {--with-logs  : Force deploying Loki + Promtail log aggregation}
        {--no-traces  : Skip deploying Tempo trace storage (~450MB RAM saved)}
        {--with-traces : Force deploying Tempo trace storage}
        {--no-plex   : Bypass Plex Commons — Grafana keeps its own database on a local PVC instead of Commons Postgres}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Deploy the cluster-wide monitoring stack (Grafana, Prometheus, Loki, Tempo) into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployMonitoring();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::GRAFANA;
    }
}
