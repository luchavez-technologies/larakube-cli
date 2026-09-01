<?php

namespace App\Commands\Tool;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasOidcWiring;
use App\Contracts\HasRotatableDatabasePassword;
use App\Contracts\HasSmtpWiring;
use App\Contracts\UsesForwardAuth;
use App\Enums\ClusterTool;
use App\Traits\InteractsWithToolRegistry;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesStandaloneEnvironment;
use App\Traits\SyncsClusterSecrets;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;
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
        {--refresh    : Rebuild the cluster registry from Deployments that follow the naming convention}
        {--dry-run    : With --refresh, show what would be written without touching the registry}
        {--json       : Emit one machine-readable JSON array on stdout}';

    protected $description = 'List LaraKube shared cluster tools and which are installed';

    public function handle(): int
    {
        if (! $this->option('json')) {
            $this->renderHeader();
        }

        [$env, $kubectl] = $this->resolveStandaloneEnvironmentAndKubectl();

        if ($this->option('refresh')) {
            return $this->refreshRegistry($kubectl);
        }

        $onlyInstalled = (bool) $this->option('installed');
        $registered = $this->getRegisteredTools($kubectl);

        $rows = [];
        foreach (ClusterTool::shippedCases() as $tool) {
            $vendor = $tool->vendor();
            $instances = array_values(array_filter(
                $registered,
                fn ($e) => ($e['tool'] ?? null) === $tool->value,
            ));

            if ($instances === []) {
                $isPresent = $this->isToolPresentOnCluster($kubectl, $tool);
                $instances = [['tool' => $tool->value, 'instance' => '', 'installed' => $isPresent]];
            } else {
                foreach ($instances as &$inst) {
                    $inst['installed'] = true;
                }
                unset($inst);
            }

            foreach ($instances as $entry) {
                $instance = $entry['instance'] ?? '';
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
                if ($instance !== '') {
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
        $openBaoReady = collect($rows)->contains(fn ($r) => $r['installed'] && $r['vendor'] instanceof HasRotatableDatabasePassword)
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

            // 3. Rotation (OpenBao DB static role). HasRotatableDatabasePassword,
            // not the broader HasDbSecretRef it extends — the latter can exist
            // on a tool whose password isn't actually rotation-safe (e.g. baked
            // into a composed connection string), which is exactly the
            // distinction supportsDatabasePasswordRotation() enforces elsewhere.
            // Using the wrong one here would show "Rotation" as available for a
            // tool secrets:wire actually refuses to touch.
            $dbRole = ($vendor instanceof HasRotatableDatabasePassword && $vendor->dbSecretRef() !== null) ? ($toolEnum->commonsDatabases($instance)[0] ?? null) : null;
            $r['db_role'] = $dbRole;

            if (! ($vendor instanceof HasRotatableDatabasePassword) || $vendor->dbSecretRef() === null) {
                $r['rotation'] = 'N/A';
            } elseif (! $installed) {
                $r['rotation'] = '—';
            } else {
                $wired = ($dbRole !== null && $openBaoReady) ? $this->staticRoleExists($kubectl, $dbRole) : null;
                $r['rotation'] = $this->rotationCell($openBaoReady, $wired);
            }

            // 4. Secrets (OpenBao KV → ExternalSecret sync). Two different
            // ExternalSecrets can carry this: the bare-named one secrets:init's
            // static KV-mirror sweep maintains, or the dynamic '{secret}-db' one
            // secrets:wire creates — and secrets:init deliberately skips the
            // static one once the dynamic one exists (they'd otherwise race),
            // so a tool that's actually been secrets:wire'd only ever has the
            // '-db' name. Checking just the bare name meant this column showed
            // "unsynced" forever for every properly-rotated tool, right next to
            // Rotation showing the opposite — checking either name reflects
            // "is some form of OpenBao sync active," which is what this column
            // is actually meant to answer.
            $syncConfig = $toolEnum->openbaoSyncConfig($instance);
            if ($syncConfig === null) {
                $r['sync'] = 'N/A';
            } elseif (! $installed) {
                $r['sync'] = '—';
            } else {
                $staticWired = trim(Process::run("{$kubectl} get externalsecret {$syncConfig['secret']} -n {$syncConfig['namespace']} --no-headers --ignore-not-found")->output()) !== '';
                $wired = $staticWired || trim(Process::run("{$kubectl} get externalsecret {$syncConfig['secret']}-db -n {$syncConfig['namespace']} --no-headers --ignore-not-found")->output()) !== '';
                $r['sync'] = $wired ? 'synced' : 'unsynced';
            }

            // 5. VPN (NetBird mesh)
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
            'wired', 'OpenBao', 'mesh', 'synced' => '✅',
            'unwired', 'public', 'manual (.env)', 'unsynced' => '❌',
            'unreachable' => '⚠️',
            'N/A' => '🚫',
            '—' => '<fg=gray>—</>',
            default => $v,
        };

        table(
            ['', 'Service', 'What it is', 'URL', 'Mail', 'SSO', 'Rotation', 'Secrets', 'VPN'],
            array_map(fn (array $r) => [
                $r['installed'] ? '<fg=green>●</>' : '<fg=gray>○</>',
                $r['brand'],
                $r['label'],
                $r['url'] ?? ($r['installed'] ? '<fg=gray>no host recorded</>' : '<fg=gray>—</>'),
                $color((string) $r['mail']),
                $color((string) $r['sso']),
                $color((string) $r['rotation']),
                $color((string) $r['sync']),
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

    /**
     * Rebuild the registry from what the cluster actually runs.
     *
     * Discovery is STRICTLY by naming convention: only `{component}-{instance}`
     * Deployments are recognised, because only those carry a recoverable
     * identity. A bare name like `stalwart` or `drive-ocis` is deliberately
     * skipped — the tool it belongs to is still found through any sibling
     * component that IS suffixed (chat via chat-web, meet via meet-livekit),
     * so an intentionally-unsuffixed component never hides its tool.
     *
     * This only ADDS or CORRECTS rows. It never deletes: a row whose Deployment
     * is gone may still be the only record of an install this cluster cannot
     * currently see, and quietly dropping it would be worse than a stale row.
     */
    protected function refreshRegistry(string $kubectl): int
    {
        $namespaces = array_values(array_unique(
            array_map(fn (ClusterTool $t) => $t->namespace(), ClusterTool::shippedCases()),
        ));

        $found = [];
        $skipped = [];
        $headless = [];

        foreach ($namespaces as $namespace) {
            $deployments = $this->clusterDeploymentNames($kubectl, $namespace);

            if ($deployments === []) {
                continue;
            }

            $hosts = $this->clusterIngressHosts($kubectl, $namespace);

            foreach ($deployments as $deployment) {
                $hit = ClusterTool::forInstancedDeployment($deployment);

                if ($hit === null) {
                    $skipped[] = $deployment;

                    continue;
                }

                $tool = $hit['tool'];

                // service() is already the codebase's model of "exposes
                // something over HTTP" — null means a background controller
                // like ExternalDNS with no ingress of its own. Reusing it
                // rather than adding a parallel NeedsDomain contract keeps one
                // source of truth; two could disagree.
                if ($tool->service() === null) {
                    $headless[$tool->value] = true;

                    continue;
                }

                $instance = $hit['instance'];
                $key = $tool->value.'|'.$instance;

                // The instance was derived FROM a host, so the matching host is
                // the one that slugifies back to it — exact, not guesswork.
                $host = $found[$key]['host'] ?? null;
                foreach ($hosts as $candidate) {
                    if ($tool->instanceSlugFromHost($candidate) === $instance) {
                        $host = $candidate;

                        break;
                    }
                }

                $found[$key] = ['tool' => $tool, 'instance' => $instance, 'host' => $host];
            }
        }

        if ($found === []) {
            $this->laraKubeError('No convention-following Deployments found on this cluster.');

            return 1;
        }

        $existing = $this->getRegisteredTools($kubectl);
        $known = [];
        foreach ($existing as $row) {
            $known[($row['tool'] ?? '').'|'.($row['instance'] ?? '')] = true;
        }

        ksort($found);
        $rows = [];
        foreach ($found as $key => $entry) {
            $rows[] = [
                $entry['tool']->value,
                $entry['instance'],
                $entry['host'] ?? '<no ingress>',
                isset($known[$key]) ? 'known' : 'NEW',
            ];
        }

        table(['Tool', 'Instance', 'Host', 'Registry'], $rows);

        if ($headless !== []) {
            $this->laraKubeNewLine();
            $this->laraKubeInfo('Skipped '.count($headless).' headless tool(s) — no host by design: '.implode(', ', array_keys($headless)));
        }

        if ($skipped !== []) {
            sort($skipped);
            $this->laraKubeNewLine();
            $this->laraKubeWarn(count($skipped).' Deployment(s) skipped — no instance suffix, so no recoverable identity:');
            foreach ($skipped as $name) {
                $this->line("  <fg=gray>- {$name}</>");
            }
            $this->line('  <fg=gray>Re-run their {tool}:init to adopt the naming convention, then refresh again.</>');
        }

        $new = array_filter($rows, fn (array $r) => $r[3] === 'NEW');

        if ($new === []) {
            $this->laraKubeNewLine();
            $this->laraKubeInfo('Registry already matches the cluster — nothing to write.');

            return 0;
        }

        $this->laraKubeNewLine();

        if ($this->option('dry-run')) {
            $this->laraKubeInfo('Dry run — '.count($new).' row(s) would be written. Nothing changed.');

            return 0;
        }

        if (! $this->option('no-interaction') && ! confirm('Write '.count($new).' new row(s) into the cluster registry?', true)) {
            $this->laraKubeInfo('Refresh cancelled — nothing written.');

            return 0;
        }

        $written = 0;
        foreach ($found as $key => $entry) {
            if (isset($known[$key])) {
                continue;
            }

            $metadata = $entry['host'] !== null ? ['host' => $entry['host']] : [];

            if ($this->registerTool($kubectl, $entry['tool'], $metadata, $entry['instance'])) {
                $written++;
            }
        }

        $this->laraKubeNewLine();
        $this->laraKubeInfo("✅ Registry refreshed — {$written} row(s) written.");

        return 0;
    }

    /** @return list<string> */
    protected function clusterDeploymentNames(string $kubectl, string $namespace): array
    {
        $out = trim(Process::run(
            "{$kubectl} get deployment -n ".escapeshellarg($namespace)
            .' -o jsonpath='.escapeshellarg('{range .items[*]}{.metadata.name}{"\n"}{end}'),
        )->output());

        return $out === '' ? [] : array_values(array_filter(array_map('trim', explode("\n", $out))));
    }

    /** @return list<string> */
    protected function clusterIngressHosts(string $kubectl, string $namespace): array
    {
        $out = trim(Process::run(
            "{$kubectl} get ingress -n ".escapeshellarg($namespace)
            .' -o jsonpath='.escapeshellarg('{range .items[*]}{.spec.rules[*].host}{"\n"}{end}'),
        )->output());

        if ($out === '') {
            return [];
        }

        $hosts = preg_split('/\s+/', $out) ?: [];

        return array_values(array_unique(array_filter(array_map('trim', $hosts))));
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
