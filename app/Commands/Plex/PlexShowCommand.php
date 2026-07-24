<?php

namespace App\Commands\Plex;

use App\Data\ConfigData;
use App\Enums\ClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsPlexCredentials;
use App\Traits\ResolvesEnvironmentContext;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

class PlexShowCommand extends Command
{
    use InteractsWithClusterContext, InteractsWithPlex, InteractsWithProjectConfig, LaraKubeOutput, ReadsPlexCredentials, ResolvesEnvironmentContext;

    protected $signature = 'plex:show
        {environment=local : The environment whose Commons to inspect (used only inside a project)}
        {--context= : Target a specific kube-context (else: the project env context, or you are prompted)}';

    protected $description = 'Show the shared Commons services and its tenants';

    public function handle(): int
    {
        $this->renderHeader();
        $this->laraKubeInfo('LaraKube Plex — Commons Status');

        // Resolve the target context like plex:init — so `plex:show` works
        // inside OR outside a project. Precedence: --context > the project env's
        // context (read-only, no prompt) > an interactive picker (outside a
        // project). isLaraKubeProject(false) just branches, without erroring.
        $config = $this->isLaraKubeProject(false) ? $this->getProjectConfig(getcwd()) : null;

        if ($this->option('context')) {
            $this->plexContext = (string) $this->option('context');
        } elseif ($config !== null) {
            $env = (string) $this->argument('environment');
            // Local → null context = current kubectl context (K3D). No error; status works.
            $this->plexContext = $this->environmentContextOrCurrent($config, $env);
        } else {
            // Outside a project (no env config to map): pick the cluster, like plex:init.
            $target = $this->askForClusterContext();
            if (! $target) {
                $this->laraKubeError('No Kubernetes context selected.');

                return 1;
            }
            $this->plexContext = $target;
        }

        $context = $this->plexContext;
        if (! $this->plexContextReachable()) {
            $this->laraKubeError('The '.($context ? "context '{$context}'" : 'current context').' is unreachable.');

            return 1;
        }

        $this->line('  <fg=gray>Context:</> <fg=cyan>'.($context ?: 'current context').'</>');

        $spec = $this->getCommonsSpec();
        if ($spec === null) {
            $this->laraKubeNewLine();
            $this->laraKubeInfo('No Commons on this cluster yet. Run `larakube plex:init`.');

            return 0;
        }

        // Services + in-cluster hosts — driven by the spec itself (service name
        // and port come from the spec, never a hardcoded list).
        $ns = $this->plexNamespace();
        $this->laraKubeNewLine();
        $this->line("  <fg=green>Commons services</> ({$ns}):");
        foreach ($spec['services'] ?? [] as $service => $cfg) {
            $on = $cfg['enabled'] ?? false;
            $mark = $on ? '<fg=green>●</>' : '<fg=gray>○</>';
            $detail = $on ? "{$service}.{$ns}.svc.cluster.local:".($cfg['port'] ?? '') : 'disabled';
            $this->line('    '.$mark.' '.str_pad($service, 12).' <fg=gray>'.$detail.'</>');
            if ($on && ! empty($cfg['host'])) {
                $this->line('      <fg=gray>public:</> <fg=cyan>https://'.$cfg['host'].'</>');
            }
            if ($on && ! empty($cfg['console_host'])) {
                $this->line('      <fg=gray>console:</> <fg=cyan>https://'.$cfg['console_host'].'</>');
            }
            if ($on && ! empty($cfg['admin_host'])) {
                $this->line('      <fg=gray>admin:</> <fg=cyan>https://'.$cfg['admin_host'].'</>');
            }
        }

        // Tenants from the registry (highlight this app if it's one).
        $tenants = $this->getRegistry()['tenants'] ?? [];
        $self = $config !== null ? $this->plexTenantIdentifier($config->getName(), (string) $this->argument('environment')) : null;

        // Partition tenants into Application Tenants (project apps) vs Cluster Tools
        $knownTools = [];
        foreach (ClusterTool::cases() as $ct) {
            $knownTools[] = $ct->value;
        }
        $knownTools = array_merge($knownTools, ['stalwart', 'vaultwarden', 'n8n', 'windmill', 'gitea', 'metabase', 'glitchtip', 'freescout', 'baserow', 'mattermost', 'zitadel', 'infisical', 'uptime-kuma']);

        $appTenants = [];
        $toolTenants = [];

        foreach ($tenants as $name => $alloc) {
            if (in_array($name, $knownTools, true)) {
                $toolTenants[$name] = $alloc;
            } else {
                $appTenants[$name] = $alloc;
            }
        }

        // Section 1: Application Tenants
        $this->laraKubeNewLine();
        if (empty($appTenants)) {
            $this->line('  <fg=gray>No Application Tenants connected to Plex yet.</>');
        } else {
            $this->line('  <fg=green>Application Tenants</> ('.count($appTenants).'):');
            foreach ($appTenants as $name => $alloc) {
                $db = $alloc['db'] ?? '—';
                if (($alloc['db'] ?? null) && ($alloc['db_service'] ?? null)) {
                    $db .= " (<fg=gray>{$alloc['db_service']}</>)";
                }
                $you = $name === $self ? ' <fg=yellow>(this app)</>' : '';
                $this->line("    <fg=cyan>{$name}</>{$you}");
                $this->line("      <fg=gray>├─ Database:</> {$db}");

                if (($alloc['redis_index'] ?? null) !== null) {
                    $this->line("      <fg=gray>├─ Redis DB:</> {$alloc['redis_index']}");
                } else {
                    $this->line('      <fg=gray>├─ Redis DB:</> <fg=gray>—</>');
                }

                if ($alloc['s3_bucket'] ?? null) {
                    $this->line("      <fg=gray>└─ S3 Bucket:</> {$alloc['s3_bucket']}");
                } else {
                    $this->line('      <fg=gray>└─ S3 Bucket:</> <fg=gray>—</>');
                }

                if ($name === $self && $config !== null) {
                    $this->showSelfCredentials($config, (string) $this->argument('environment'));
                }
            }
        }

        // Section 2: Cluster Tools using Plex (Merge registered tool tenants + live deployment scan)
        $kubectl = $this->plexKubectl();
        $json = Process::run("{$kubectl} get deployments -A -o json")->output();
        $deployments = json_decode($json, true)['items'] ?? [];
        $plexDeployments = [];

        foreach ($deployments as $deploy) {
            $deployNs = $deploy['metadata']['namespace'] ?? '';
            $deployName = $deploy['metadata']['name'] ?? '';
            if ($deployNs === 'larakube-plex' || $deployNs === 'kube-system') {
                continue;
            }

            $specJson = json_encode($deploy);
            if (str_contains($specJson, 'larakube-plex')) {
                $plexDeployments[$deployNs.'/'.$deployName] = [
                    'name' => $deployName,
                    'ns' => $deployNs,
                    'json' => $specJson,
                ];
            }
        }

        $allClusterTools = [];

        foreach ($toolTenants as $name => $alloc) {
            $db = $alloc['db'] ?? '—';
            if (($alloc['db'] ?? null) && ($alloc['db_service'] ?? null)) {
                $db .= " ({$alloc['db_service']})";
            }
            $toolEnum = ClusterTool::tryFrom($name) ?? match ($name) {
                'stalwart' => ClusterTool::MAIL,
                'vaultwarden' => ClusterTool::PASSWORDS,
                default => null,
            };

            $label = $toolEnum !== null ? $toolEnum->getLabel() : $name;
            $allClusterTools[$name] = [
                'label' => $label,
                'db' => $db,
                'redis' => ($alloc['redis_index'] ?? null) !== null ? (string) $alloc['redis_index'] : '—',
                's3' => $alloc['s3_bucket'] ?? '—',
            ];
        }

        foreach (ClusterTool::cases() as $tool) {
            $smtpEnv = $tool->smtpEnv();
            $oidcEnv = $tool->oidcEnv();

            $toolNs = $smtpEnv['namespace'] ?? $oidcEnv['namespace'] ?? 'larakube-shared';
            $toolDeployment = $smtpEnv['deployment'] ?? $oidcEnv['deployment'] ?? null;

            if ($toolDeployment === null) {
                $toolDeployment = match ($tool) {
                    ClusterTool::GIT => 'gitea',
                    ClusterTool::INSIGHTS => 'metabase',
                    ClusterTool::ERRORS => 'glitchtip-web',
                    ClusterTool::UPTIME => 'uptime-kuma',
                    ClusterTool::SECRETS => 'infisical',
                    ClusterTool::DESK => 'freescout',
                    ClusterTool::CHAT => 'mattermost',
                    ClusterTool::FLOW => 'n8n',
                    default => $tool->value,
                };
                if ($tool === ClusterTool::PASSWORDS) {
                    $toolNs = 'larakube-vault';
                }
                if ($tool === ClusterTool::SECRETS) {
                    $toolNs = 'larakube-secrets';
                }
                if ($tool === ClusterTool::SSO) {
                    $toolNs = 'larakube-sso';
                }
            }

            $key = "{$toolNs}/{$toolDeployment}";
            if (isset($plexDeployments[$key])) {
                $liveDb = null;
                $liveS3 = null;

                foreach ($deploy['spec']['template']['spec']['containers'] ?? [] as $c) {
                    foreach ($c['env'] ?? [] as $envVar) {
                        $eName = $envVar['name'] ?? '';
                        $eVal = $envVar['value'] ?? '';
                        if (in_array($eName, ['DB_DATABASE', 'POSTGRES_DB', 'POSTGRES_DATABASE', 'DATABASE_NAME', 'DB_NAME'], true) && $eVal !== '') {
                            $liveDb = $eVal;
                        }
                        if (in_array($eName, ['AWS_BUCKET', 'AWS_S3_BUCKET', 'S3_BUCKET', 'MM_FILESETTINGS_AMAZONS3BUCKET'], true) && $eVal !== '') {
                            $liveS3 = $eVal;
                        }
                    }
                }

                $dbDbs = $tool->commonsDatabases();
                $dbName = $liveDb ?? ($dbDbs[0] ?? $tool->value);

                $engine = 'postgres';
                if (str_contains($specJson, 'mysql.')) {
                    $engine = 'mysql';
                } elseif (str_contains($specJson, 'mariadb.')) {
                    $engine = 'mariadb';
                }

                $db = "{$dbName} ({$engine})";

                $redis = str_contains($specJson, 'redis.') ? '0' : '—';
                $hasS3 = (str_contains($specJson, 'minio.') || str_contains($specJson, 'seaweedfs.') || str_contains($specJson, 'garage.') || str_contains($specJson, 'AWS_ENDPOINT') || str_contains($specJson, 'MM_FILESETTINGS_AMAZONS3BUCKET'));
                $s3 = $liveS3 ?? ($hasS3 ? $dbName : '—');

                $toolName = $tool->value;
                if (! isset($allClusterTools[$toolName])) {
                    $allClusterTools[$toolName] = [
                        'label' => $tool->getLabel(),
                        'db' => $db,
                        'redis' => $redis,
                        's3' => $s3,
                    ];
                }
            }
        }

        $this->laraKubeNewLine();
        if (! empty($allClusterTools)) {
            $this->line('  <fg=green>Cluster Tools using Plex</> ('.count($allClusterTools).'):');
            foreach ($allClusterTools as $name => $t) {
                $this->line("    <fg=cyan>{$t['label']}</> <fg=gray>({$name})</>");
                $this->line("      <fg=gray>├─ Database:</> {$t['db']}");
                $this->line("      <fg=gray>├─ Redis DB:</> {$t['redis']}");
                $this->line("      <fg=gray>└─ S3 Bucket:</> {$t['s3']}");
            }
        }

        $this->newLine();

        return 0;
    }

    /**
     * Print the joined Commons credentials for this app (DB / Redis / S3), read
     * from the project's .env / .env.{env}. No-op when not a tenant for this env.
     */
    private function showSelfCredentials(ConfigData $config, string $env): void
    {
        $creds = $this->plexTenantCredentials($config, getcwd(), $env);

        if ($creds === []) {
            return;
        }

        $source = $env === 'local' ? '.env' : ".env.{$env}";
        $this->line("      <fg=gray>credentials</> <fg=gray>(from {$source}):</>");

        foreach ($creds as $section => $pairs) {
            foreach ($pairs as $key => $value) {
                $this->line("        <fg=gray>{$section} {$key}:</> {$value}");
            }
        }
    }
}
