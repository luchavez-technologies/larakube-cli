<?php

namespace App\Commands\Tool;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasDbSecretRef;
use App\Contracts\HasOidcWiring;
use App\Contracts\HasSmtpWiring;
use App\Contracts\UsesForwardAuth;
use App\Enums\ClusterTool;
use App\Traits\InteractsWithToolRegistry;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesStandaloneEnvironment;
use App\Traits\SyncsClusterSecrets;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

/**
 * Show every shared cluster tool and whether it is installed on this cluster.
 *
 * The cluster registry (the `larakube-tools-registry` Secret) is the single
 * source of truth — deliberately NOT `.larakube.json`. These tools are cluster
 * infrastructure, not properties of any one Laravel app: a cluster can host
 * many projects, and a project can target many clusters, so recording tool
 * state in a project file would be both wrong and unshareable. Asking the
 * cluster means the answer is correct from any machine, in any project, or in
 * no project at all.
 */
class ToolListCommand extends Command
{
    use InteractsWithToolRegistry, LaraKubeOutput, ResolvesStandaloneEnvironment, SyncsClusterSecrets;

    protected $signature = 'tool:list
        {environment? : The environment to inspect}
        {--context=   : Target a specific kube-context}
        {--installed  : Only list tools that are actually installed}
        {--json       : Emit one machine-readable JSON array on stdout}';

    protected $description = 'List LaraKube shared cluster tools and which are installed';

    public function handle(): int
    {
        if (! $this->option('json')) {
            $this->renderHeader();
        }

        [$env, $kubectl] = $this->resolveStandaloneEnvironmentAndKubectl();

        $onlyInstalled = (bool) $this->option('installed');
        $registered = $this->getRegisteredTools($kubectl);

        $rows = [];
        foreach (ClusterTool::cases() as $tool) {
            $vendor = $tool->vendor();
            $instances = array_values(array_filter(
                $registered,
                fn ($e) => ($e['tool'] ?? null) === $tool->value,
            ));

            if ($instances === []) {
                $isPresent = $this->isToolPresentOnCluster($kubectl, $tool);
                $instances = [['tool' => $tool->value, 'instance' => 'main', 'installed' => $isPresent]];
            } else {
                foreach ($instances as &$inst) {
                    $inst['installed'] = true;
                }
                unset($inst);
            }

            foreach ($instances as $entry) {
                $instance = $entry['instance'] ?? 'main';
                $installed = (bool) ($entry['installed'] ?? true);

                if ($onlyInstalled && ! $installed) {
                    continue;
                }

                $host = $entry['host'] ?? null;
                if ($installed && ($host === null || $host === '')) {
                    $host = $this->resolveLiveToolHost($kubectl, $tool, $instance);
                    if ($host !== null && $host !== '') {
                        $this->registerTool($kubectl, $tool, ['host' => $host], $instance);
                    }
                }

                $aliasHosts = $entry['aliases'] ?? [];
                $aliasSuffix = $aliasHosts !== [] ? ' (+'.count($aliasHosts).' alias)' : '';

                $serviceLabel = $tool->brandName();
                if ($instance !== 'main') {
                    $serviceLabel .= " [{$instance}]";
                }

                $rows[] = [
                    'tool' => $tool->value,
                    'instance' => $instance,
                    'icon' => $tool->icon(),
                    'brand' => $serviceLabel,
                    'label' => $tool->getLabel(),
                    'installed' => $installed,
                    'namespace' => $tool->namespace(),
                    'host' => $host,
                    'aliases' => $aliasHosts,
                    'url' => $host !== null ? 'https://'.$host.$aliasSuffix : null,
                    'installedAt' => $entry['installedAt'] ?? null,
                    'vendor' => $vendor,
                ];
            }
        }

        // Readiness checks up front
        $openBaoReady = collect($rows)->contains(fn ($r) => $r['installed'] && $r['vendor'] instanceof HasDbSecretRef)
            ? $this->isOpenBaoBootstrapped($kubectl, $this->secretsNamespace())
            : false;

        foreach ($rows as &$r) {
            /** @var ClusterToolVendor $vendor */
            $vendor = $r['vendor'];
            $installed = $r['installed'];
            $instance = $r['instance'];
            $toolEnum = ClusterTool::from($r['tool']);
            $ns = $toolEnum->namespace();

            // 1. Mail (SMTP)
            if (! ($vendor instanceof HasSmtpWiring) || $vendor->smtpEnv($instance) === null) {
                $r['mail'] = 'N/A';
            } elseif (! $installed) {
                $r['mail'] = '—';
            } else {
                $smtpSecret = $vendor->smtpEnv($instance)['secret'] ?? "{$r['tool']}-smtp";
                $wired = trim(Process::run("{$kubectl} get secret {$smtpSecret} -n {$ns} --no-headers --ignore-not-found")->output()) !== '';
                $r['mail'] = $wired ? 'wired' : 'unwired';
            }

            // 2. SSO (OIDC / ForwardAuth)
            $isOidc = $vendor instanceof HasOidcWiring && $vendor->oidcEnv($instance) !== null;
            $isFwdAuth = $vendor instanceof UsesForwardAuth;
            if (! $isOidc && ! $isFwdAuth) {
                $r['sso'] = 'N/A';
            } elseif (! $installed) {
                $r['sso'] = '—';
            } else {
                if ($isOidc) {
                    $oidcSecret = $vendor->oidcEnv($instance)['secret'] ?? "{$r['tool']}-oidc";
                    $wired = trim(Process::run("{$kubectl} get secret {$oidcSecret} -n {$ns} --no-headers --ignore-not-found")->output()) !== '';
                } else {
                    $wired = trim(Process::run("{$kubectl} get middleware sso-proxy-auth -n larakube-shared --no-headers --ignore-not-found")->output()) !== '';
                }
                $r['sso'] = $wired ? 'wired' : 'unwired';
            }

            // 3. Rotation (OpenBao DB static role)
            $dbRole = ($vendor instanceof HasDbSecretRef && $vendor->dbSecretRef() !== null) ? ($toolEnum->commonsDatabases($instance)[0] ?? null) : null;
            $r['db_role'] = $dbRole;

            if (! ($vendor instanceof HasDbSecretRef) || $vendor->dbSecretRef() === null) {
                $r['rotation'] = 'N/A';
            } elseif (! $installed) {
                $r['rotation'] = '—';
            } else {
                $wired = ($dbRole !== null && $openBaoReady) ? $this->staticRoleExists($kubectl, $dbRole) : null;
                $r['rotation'] = $this->rotationCell($openBaoReady, $wired);
            }

            // 4. VPN (NetBird mesh)
            $vpnTarget = $toolEnum->vpnMiddlewareTarget($instance);
            if ($vpnTarget === null) {
                $r['vpn'] = 'N/A';
            } elseif (! $installed) {
                $r['vpn'] = '—';
            } else {
                $mwName = $vpnTarget['name'];
                $mwNs = $vpnTarget['namespace'];
                $wired = trim(Process::run("{$kubectl} get middleware {$mwName} -n {$mwNs} --no-headers --ignore-not-found")->output()) !== '';
                $r['vpn'] = $wired ? 'mesh' : 'public';
            }

            unset($r['vendor']);
        }
        unset($r);

        if ($this->option('json')) {
            $this->line((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        if ($rows === []) {
            $this->laraKubeInfo('No tools installed on this cluster yet.');
            $this->line('  <fg=gray>Install one with</> <fg=blue>larakube tool:add</><fg=gray>.</>');

            return 0;
        }

        $color = fn (string $v) => match ($v) {
            'wired', 'OpenBao', 'mesh' => "<fg=green>{$v}</>",
            'unwired', 'public', 'manual (.env)' => "<fg=gray>{$v}</>",
            'unreachable' => "<fg=yellow>{$v}</>",
            'N/A', '—' => "<fg=gray>{$v}</>",
            default => $v,
        };

        table(
            ['', 'Service', 'What it is', 'URL', 'Mail', 'SSO', 'Rotation', 'VPN'],
            array_map(fn (array $r) => [
                $r['installed'] ? '<fg=green>●</>' : '<fg=gray>○</>',
                $r['brand'],
                $r['label'],
                $r['url'] ?? ($r['installed'] ? '<fg=gray>no host recorded</>' : '<fg=gray>—</>'),
                $color((string) $r['mail']),
                $color((string) $r['sso']),
                $color((string) $r['rotation']),
                $color((string) $r['vpn']),
            ], $rows),
        );

        $installedCount = count(array_filter($rows, fn ($r) => $r['installed']));

        $this->newLine();
        $this->line("  <fg=green>●</> installed ({$installedCount})   <fg=gray>○ available</>");
        $this->line("  <fg=gray>Details for one tool:</> <fg=blue>larakube tool:show {$env} --tool=<slug></>");
        $this->newLine();

        return 0;
    }

    private function rotationCell(bool $openBaoReady, ?bool $wired): string
    {
        if (! $openBaoReady) {
            return 'manual (.env)';
        }

        if ($wired === null) {
            return 'unreachable';
        }

        return $wired ? 'OpenBao' : 'manual (.env)';
    }
}
