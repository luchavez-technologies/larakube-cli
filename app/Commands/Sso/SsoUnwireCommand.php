<?php

namespace App\Commands\Sso;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\ClusterTool;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithChat;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithSso;
use App\Traits\InteractsWithZitadelApi;
use App\Traits\LaraKubeOutput;
use App\Traits\RefusesUnshippedTools;
use App\Traits\ResolvesToolEngine;
use App\Traits\ResolvesToolHost;
use App\Traits\SyncsClusterSecrets;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;
use Spatie\TemporaryDirectory\TemporaryDirectory;

class SsoUnwireCommand extends Command
{
    use DeploysClusterTool, InteractsWithChat, InteractsWithClusterContext, InteractsWithSso, InteractsWithZitadelApi, LaraKubeOutput, RefusesUnshippedTools, ResolvesToolEngine, ResolvesToolHost, SyncsClusterSecrets;

    protected $signature = 'sso:unwire
        {environment=local : Environment whose tool SSO to unwire}
        {--tool= : The tool to unwire from Zitadel SSO}
        {--engine= : Specific engine to target explicitly, skipping auto-detection (e.g. --engine=pocketbase)}
        {--domain= : The instance to target (e.g. --domain=blog.example.com). Omit for the default instance}
        {--context= : Target a specific kube-context}
        {--project= : Zitadel project name (default: LaraKube Shared Tools)}';

    protected $description = 'Unwire a tool from Zitadel SSO and deregister its OIDC application';

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');
        $context = $this->resolveToolContext($env, $this->option('context'));
        $kubectl = $this->ssoKubectl($context);
        $ssoNs = $this->ssoNamespace();

        $tool = $this->resolveTool($kubectl);
        if ($tool === null) {
            return 1;
        }

        if (! $tool->hasSsoWire()) {
            $this->laraKubeError("'{$tool->value}' can't be unwired from SSO.");

            return 1;
        }

        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;

        // Same --domain= = target instance's host resolution as sso:wire —
        // omitting it unwires the tool's default instance, same as before
        // --domain= existed.
        $domainOption = (string) ($this->option('domain') ?: '');
        $toolHost = $domainOption !== ''
            ? $this->sanitizeDomainInput($domainOption)
            : $this->targetHost($tool, $env, $config, $kubectl);
        $instance = $toolHost !== null ? $this->resolveInstanceForDomain($kubectl, $tool, $toolHost) : '';

        $engine = $this->resolveInstanceEngine($kubectl, $tool, $instance, $this->option('engine'));
        $schema = $tool->oidcEnv($engine, $instance);
        if ($schema === null) {
            return 1;
        }

        if (! $this->isSsoInstalled($kubectl, $ssoNs)) {
            $this->laraKubeError('Zitadel is not installed.');

            return 1;
        }

        $ssoHost = $this->resolveSsoHostReadOnly($env, $config, $kubectl);
        $pat = $this->readSsoSecret($kubectl, $ssoNs, 'machine-pat');

        if ($ssoHost === null || $pat === null) {
            $this->laraKubeError("Could not reach Zitadel's automation credentials or host for '{$env}'.");

            return 1;
        }

        return $this->unwire($tool, $schema, $kubectl, $ssoNs, $ssoHost, $pat);
    }

    protected function resolveTool(string $kubectl): ?ClusterTool
    {
        $option = (string) ($this->option('tool') ?? '');
        if ($option !== '') {
            $tool = ClusterTool::tryFrom($option);
            if ($tool === null) {
                $this->laraKubeError("Unknown tool '{$option}'.");

                return null;
            }
            if ($this->refuseUnshippedTool($tool)) {
                return null;
            }

            return $tool;
        }

        if ($this->option('no-interaction')) {
            $this->laraKubeError('Passing --tool is required when running in non-interactive mode.');

            return null;
        }

        $wired = [];
        foreach (ClusterTool::shippedCases() as $candidate) {
            if ($candidate->hasSsoWire()) {
                $wired[$candidate->value] = $candidate->getLabel();
            }
        }

        $selected = \Laravel\Prompts\select(
            label: 'Which tool do you want to unwire from Zitadel SSO?',
            options: $wired,
        );

        return ClusterTool::from($selected);
    }

    protected function unwire(ClusterTool $tool, array $schema, string $kubectl, string $ssoNs, string $ssoHost, string $pat): int
    {
        if ($tool->usesForwardAuth()) {
            return $this->unwireForwardAuth($tool, $schema, $kubectl, $ssoNs, $ssoHost, $pat);
        }

        $appSecret = "sso-app-{$tool->value}";
        $projectId = $this->readClusterSecretKey($kubectl, $ssoNs, $appSecret, 'project-id');
        $appId = $this->readClusterSecretKey($kubectl, $ssoNs, $appSecret, 'app-id');

        if ($projectId !== null && $appId !== null) {
            $this->withSpin("Deregistering {$tool->getLabel()} from Zitadel...", fn () => $this->zitadelDeleteOidcApp($ssoHost, $pat, $projectId, $appId));
        }

        Process::run("{$kubectl} delete secret {$appSecret} -n {$ssoNs} --ignore-not-found");
        Process::run("{$kubectl} delete secret {$schema['secret']} -n {$schema['namespace']} --ignore-not-found");

        if ($schema['deployment'] === 'chat-synapse') {
            $this->unwireSynapseOidc($kubectl, $schema['namespace']);
            Process::run("{$kubectl} rollout restart deployment/chat-synapse -n {$schema['namespace']}");
            $this->laraKubeInfo("✅ {$tool->getLabel()} no longer uses Zitadel SSO.");

            return 0;
        }

        if ($schema['deployment'] === 'openbao-backend') {
            $this->unwireOpenBaoOidc($kubectl, $schema['namespace']);
            $this->laraKubeInfo("✅ {$tool->getLabel()} no longer uses Zitadel SSO.");

            return 0;
        }

        $unset = array_values($schema['vars']);
        if (! empty($schema['static'])) {
            $unset = array_merge($unset, array_keys($schema['static']));
        }
        if (! empty($schema['sso_only_vars'])) {
            $unset = array_merge($unset, array_keys($schema['sso_only_vars']));
        }

        if ($tool->usesCliOidc()) {
            $exec = "{$kubectl} exec deploy/{$schema['deployment']} -n {$schema['namespace']} -- su-exec git forgejo --config /data/gitea/conf/app.ini admin auth";
            $sourceId = $this->findForgejoOidcSourceId($exec);
            if ($sourceId !== null) {
                $this->withSpin("Removing the Zitadel login source from {$tool->getLabel()}...", fn () => Process::run("{$exec} delete --id {$sourceId}")->successful());
            }

            $this->laraKubeInfo("✅ {$tool->getLabel()} no longer uses Zitadel SSO.");

            return 0;
        }

        $pairs = implode(' ', array_map(fn (string $key) => $key.'-', $unset));

        $ok = true;
        $this->withSpin("Unwiring {$tool->getLabel()} from Zitadel...", function () use ($kubectl, $schema, $pairs, &$ok): void {
            $ok = Process::run("{$kubectl} set env deployment/{$schema['deployment']} -n {$schema['namespace']} {$pairs}")->successful();
            if ($ok) {
                Process::run("{$kubectl} rollout restart deployment/{$schema['deployment']} -n {$schema['namespace']}");
            }
        });

        if (! $ok) {
            $this->laraKubeError("Failed to unwire {$tool->getLabel()} from Zitadel.");

            return 1;
        }

        $this->laraKubeInfo("✅ {$tool->getLabel()} no longer uses Zitadel SSO.");

        return 0;
    }

    protected function unwireForwardAuth(ClusterTool $tool, array $schema, string $kubectl, string $ssoNs, string $ssoHost, string $pat): int
    {
        $env = (string) $this->argument('environment');
        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;
        $toolHost = $this->targetHost($tool, $env, $config, $kubectl);

        $ok = true;
        if ($toolHost !== null) {
            $ok = $this->applyToolIngress($kubectl, $tool, $toolHost, false, $env === 'local');
        }

        Process::run("{$kubectl} delete middleware sso-forwardauth -n {$schema['namespace']} --ignore-not-found");

        if ($this->gatedForwardAuthTools($kubectl, $tool) === []) {
            $this->withSpin('No gated tools left — removing the shared SSO proxy...', function () use ($kubectl, $ssoNs, $ssoHost, $pat): void {
                $projectId = $this->readClusterSecretKey($kubectl, $ssoNs, 'sso-app-proxy', 'project-id');
                $appId = $this->readClusterSecretKey($kubectl, $ssoNs, 'sso-app-proxy', 'app-id');
                if ($projectId !== null && $appId !== null) {
                    $this->zitadelDeleteOidcApp($ssoHost, $pat, $projectId, $appId);
                }

                $ns = 'larakube-shared';
                Process::run("{$kubectl} delete ingress sso-proxy -n {$ns} --ignore-not-found");
                Process::run("{$kubectl} delete service sso-proxy -n {$ns} --ignore-not-found");
                Process::run("{$kubectl} delete deployment sso-proxy -n {$ns} --ignore-not-found");
                Process::run("{$kubectl} delete secret sso-proxy -n {$ns} --ignore-not-found");
                Process::run("{$kubectl} delete secret sso-app-proxy -n {$ssoNs} --ignore-not-found");
            });
        }

        if (! $ok) {
            $this->laraKubeError("Failed to unwire {$tool->getLabel()} from Zitadel.");

            return 1;
        }

        $this->laraKubeInfo("✅ {$tool->getLabel()} is no longer gated behind SSO.");

        return 0;
    }

    protected function targetHost(ClusterTool $tool, string $env, ?ConfigData $config, ?string $kubectl = null): ?string
    {
        $service = $tool->service();
        if ($service === null) {
            return null;
        }

        if ($env === 'local') {
            return $service->hostFor(GlobalConfigData::load()->getLocalTld());
        }

        // Same fallback as SsoWireCommand::targetHost() — newer :init commands
        // record cloud hosts in the cluster registry, not .larakube.json.
        if ($kubectl !== null) {
            $registered = $this->resolveLiveToolHost($kubectl, $tool);
            if ($registered !== null && $registered !== '') {
                return $registered;
            }
        }

        return $config?->getEnvironment($env)?->hosts[$service->value] ?? null;
    }

    protected function gatedForwardAuthTools(string $kubectl, ?ClusterTool $exclude = null): array
    {
        $gated = [];
        foreach (ClusterTool::cases() as $candidate) {
            if ($exclude !== null && $candidate === $exclude) {
                continue;
            }
            if (! $candidate->usesForwardAuth()) {
                continue;
            }

            $annotations = Process::run(
                "{$kubectl} get ingress {$candidate->value} -n {$candidate->namespace()} "
                ."-o jsonpath='{.metadata.annotations}' --ignore-not-found",
            )->output();

            if (str_contains($annotations, 'sso-forwardauth')) {
                $gated[] = $candidate->value;
            }
        }

        return $gated;
    }

    protected function applyToolIngress(string $kubectl, ClusterTool $tool, string $host, bool $ssoWired, bool $isLocal): bool
    {
        $view = "k8s.{$tool->value}.ingress";
        if (! view()->exists($view)) {
            $this->laraKubeError("No ingress template for '{$tool->value}' — cannot toggle its SSO middleware.");

            return false;
        }

        return $this->applyManifest($kubectl, view($view, [
            'host' => $host,
            'ssoWired' => $ssoWired,
            'isLocal' => $isLocal,
            'vpnOnly' => str_contains(Process::run("{$kubectl} get ingress {$tool->value} -n {$tool->namespace()} -o jsonpath='{.metadata.annotations}' --ignore-not-found")->output(), 'vpn-only'),
            'proxied' => str_contains(Process::run("{$kubectl} get ingress {$tool->value} -n {$tool->namespace()} -o jsonpath='{.metadata.annotations}' --ignore-not-found")->output(), 'cloudflare-proxied'),
        ])->render(), "{$tool->value}-ingress");
    }

    protected function applyManifest(string $kubectl, string $yaml, string $name): bool
    {
        $temporaryDirectory = TemporaryDirectory::make();
        $tmp = $temporaryDirectory->path("larakube-{$name}.yaml");
        file_put_contents($tmp, $yaml);
        $result = Process::run("{$kubectl} apply -f {$tmp}");
        $temporaryDirectory->delete();

        return $result->successful();
    }

    protected function unwireSynapseOidc(string $kubectl, string $ns): void
    {
        $smtp = $this->readChatWiredSmtp($kubectl, $ns);
        $raw = trim(Process::run("{$kubectl} get secret chat-synapse-config -n {$ns} -o jsonpath='{.data.homeserver\.yaml}'")->output());
        if ($raw === '') {
            return;
        }

        $rawYaml = (string) base64_decode($raw);
        $homeserver = $this->renderSynapseConfig($rawYaml, $smtp, null);

        $temporaryDirectory = (new TemporaryDirectory)->permission(0700)->deleteWhenDestroyed()->create();
        $tmp = $temporaryDirectory->path().'/homeserver.yaml';
        file_put_contents($tmp, $homeserver);
        $result = Process::run(
            "{$kubectl} create secret generic chat-synapse-config -n {$ns} --from-file=homeserver.yaml={$tmp} --dry-run=client -o yaml | {$kubectl} apply -f -",
        );
        $temporaryDirectory->delete();

        if ($result->successful()) {
            Process::run("{$kubectl} rollout restart deployment/chat-synapse -n {$ns}");
        }
    }

    protected function unwireOpenBaoOidc(string $kubectl, string $ns): void
    {
        $rootToken = $this->readClusterSecretKey($kubectl, $ns, 'openbao-bootstrap', 'root-token');
        if ($rootToken === null) {
            return;
        }

        $exec = "{$kubectl} exec deploy/openbao-backend -n {$ns} -- env BAO_TOKEN=".escapeshellarg($rootToken).' BAO_ADDR=http://127.0.0.1:8200';
        Process::run("{$exec} bao auth disable oidc");
    }
}
