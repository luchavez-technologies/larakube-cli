<?php

namespace App\Commands\Dashboard;

use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithDashboard;
use App\Traits\InteractsWithIngressProxy;
use App\Traits\InteractsWithPlex;
use App\Traits\LaraKubeOutput;
use App\Traits\ManagesToolFirewallPorts;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\ResolvesToolBranding;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\ResolvesToolHost;
use App\Traits\StreamsProcessOutput;
use App\Traits\VerifiesKubernetesRollout;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

class DashboardInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithClusterContext, InteractsWithDashboard, InteractsWithIngressProxy, InteractsWithPlex, LaraKubeOutput, ManagesToolFirewallPorts, RequiresFlagsWhenNonInteractive, ResolvesToolBranding, ResolvesToolEnvironment, ResolvesToolHost, StreamsProcessOutput, VerifiesKubernetesRollout;

    protected $signature = 'dashboard:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=  : Target a specific kube-context}
        {--domain=   : Base domain OR full host for Dashboard (example.com → dashboard.example.com)}
        {--app-name= : Custom branding name for Headlamp}
        {--logo-url= : Custom logo URL for Headlamp}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Deploy the CNCF Headlamp Kubernetes web control plane into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployDashboard();
    }

    protected function deployDashboard(): int
    {
        $env = $this->resolveToolEnvironment(ClusterTool::DASHBOARD);
        $context = $this->resolveToolContext($env, $this->option('context'));
        $this->plexContext = $context;
        $kubectl = $this->dashboardKubectl($context);
        $host = $this->resolveToolHost(SharedClusterService::DASHBOARD, ClusterTool::DASHBOARD, $env, $kubectl);
        $ns = $this->dashboardNamespace();
        $vpnOnly = (bool) $this->option('vpn-only');

        if ($vpnOnly && ! $this->ensureVpnMiddleware(ClusterTool::DASHBOARD, $kubectl)) {
            $this->laraKubeError('Failed to create the VPN-only Middleware — check kubectl access to the cluster above and re-run.');

            return 1;
        }

        $this->withSpin("Ensuring namespace {$ns}...", fn () => Process::run(
            "{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -",
        ));

        $oidc = $this->readDashboardWiredOidc($kubectl, $ns);
        $branding = $this->resolveToolBranding($kubectl, ClusterTool::DASHBOARD);

        $manifest = view('k8s.dashboard.headlamp', [
            'host' => $host,
            'appName' => $branding['appName'],
            'logoUrl' => $branding['logoUrl'],
            'oidc' => $oidc,
            'vpnOnly' => $vpnOnly,
            'isLocal' => $env === 'local',
            'proxied' => $this->resolveProxied($env === 'local'),
        ])->render();

        $tmp = sys_get_temp_dir().'/larakube-dashboard-headlamp.yaml';
        file_put_contents($tmp, $manifest);

        $rolledOut = $this->withSpin(
            'Applying Headlamp Control Plane manifests...',
            fn () => $this->applyAndVerifyRollout($kubectl, $tmp, $ns, 'dashboard-headlamp', 180),
        );
        @unlink($tmp);

        if (! $rolledOut) {
            return 1;
        }

        $this->registerDeployedTool(ClusterTool::DASHBOARD, $kubectl, $host);

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ CNCF Headlamp Kubernetes Control Plane is live.');
        $this->newLine();
        $this->line("  <fg=gray>Access URL:</>  <fg=blue>https://{$host}</>");
        $this->line('  <fg=gray>Auth Mode:</>   '.($oidc ? '<fg=green>Zitadel OIDC SSO</>' : '<fg=yellow>Native K8s ServiceAccount Token</>'));
        $this->newLine();

        return 0;
    }
}
