<?php

namespace App\Commands\Sso;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\ClusterTool;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithChat;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithSso;
use App\Traits\InteractsWithVpn;
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
    use DeploysClusterTool, InteractsWithChat, InteractsWithClusterContext, InteractsWithSso, InteractsWithVpn, InteractsWithZitadelApi, LaraKubeOutput, RefusesUnshippedTools, ResolvesToolEngine, ResolvesToolHost, SyncsClusterSecrets;

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

        $selection = $this->resolveTool($kubectl);
        if ($selection === null) {
            return 1;
        }
        [$tool, $pickedHost] = $selection;

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
        // The picker resolved a concrete instance, so its host wins — same as
        // sso:wire, so the two sides target the same thing.
        $toolHost = match (true) {
            $domainOption !== '' => $this->sanitizeDomainInput($domainOption),
            $pickedHost !== null => $pickedHost,
            default => $this->targetHost($tool, $env, $config, $kubectl),
        };
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

        return $this->unwire(
            $tool, $schema, $kubectl, $ssoNs, $ssoHost, $pat, $toolHost,
            // Same slug sso:wire used, so unwire targets the Secret it wrote.
            $toolHost !== null ? $tool->instanceSlugFromHost($toolHost) : null,
        );
    }

    /** @return array{0: ClusterTool, 1: ?string}|null tool + the chosen instance's host */
    protected function resolveTool(string $kubectl): ?array
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

            return [$tool, null];
        }

        if ($this->option('no-interaction')) {
            $this->laraKubeError('Passing --tool is required when running in non-interactive mode.');

            return null;
        }

        // Registry-driven and filtered to what is ACTUALLY wired, mirroring
        // sso:wire. Listing every SSO-capable tool offered things that were
        // never installed, and offering an unwired tool makes a no-op look like
        // it did something.
        $choices = [];

        foreach ($this->getRegisteredTools($kubectl) as $entry) {
            $candidate = ClusterTool::tryFrom((string) ($entry['tool'] ?? ''));

            if ($candidate === null || ! $candidate->isShipped() || ! $candidate->hasSsoWire()) {
                continue;
            }

            $host = (string) ($entry['host'] ?? '');
            $instance = $host !== '' ? $candidate->instanceSlugFromHost($host) : null;
            $schema = $candidate->oidcEnv(instance: $instance);

            // The marker Secret sso:wire writes is what "wired" means.
            if ($schema === null || ! $this->secretExists($kubectl, $schema['namespace'], $schema['secret'])) {
                continue;
            }

            $choices[$candidate->value.'|'.$host] = [
                'tool' => $candidate,
                'host' => $host !== '' ? $host : null,
                'label' => $host !== '' ? "{$candidate->getLabel()} ({$host})" : $candidate->getLabel(),
            ];
        }

        if ($choices === []) {
            $this->laraKubeError('No tools are currently wired to Zitadel SSO.');

            return null;
        }

        // STRING keys: an integer-keyed array is a LIST to Laravel Prompts, which
        // then returns the label instead of the key — the bug that made sso:wire
        // wire Matrix when NetBird was picked (2026-08-29).
        $key = (string) \Laravel\Prompts\select(
            label: 'Which tool do you want to unwire from Zitadel SSO?',
            options: array_map(fn (array $c): string => $c['label'], $choices),
            scroll: min(count($choices), 15),
        );

        return isset($choices[$key]) ? [$choices[$key]['tool'], $choices[$key]['host']] : null;
    }

    protected function secretExists(string $kubectl, string $ns, string $secret): bool
    {
        return trim(Process::run(
            "{$kubectl} get secret {$secret} -n {$ns} --no-headers --ignore-not-found",
        )->output()) !== '';
    }

    protected function unwire(ClusterTool $tool, array $schema, string $kubectl, string $ssoNs, string $ssoHost, string $pat, ?string $toolHost = null, ?string $instance = null): int
    {
        if ($tool->usesForwardAuth()) {
            return $this->unwireForwardAuth($tool, $schema, $kubectl, $ssoNs, $ssoHost, $pat);
        }

        $appSecret = $this->ssoAppSecretName($tool, $instance);
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

        if ($tool === ClusterTool::VPN) {
            if ($toolHost !== null) {
                $this->unwireNetbirdOidc($kubectl, $schema['namespace'], $toolHost);
            }
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
        // Read back MAS state so this doesn't clobber an already-active MAS
        // mode — renderSynapseConfig() always prefers $mas over oidc_providers:, so
        // this is a safe no-op on the auth block whenever MAS is active
        // (there's no oidc_providers: block for it to unwire in that case).
        $mas = $this->readChatWiredMas($kubectl, $ns);
        $raw = trim(Process::run("{$kubectl} get secret chat-synapse-config -n {$ns} -o jsonpath='{.data.homeserver\.yaml}'")->output());
        if ($raw === '') {
            return;
        }

        $rawYaml = (string) base64_decode($raw);
        $homeserver = $this->renderSynapseConfig($rawYaml, $smtp, null, $mas);

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

    /**
     * Deregister NetBird's Zitadel identity provider via its own REST API —
     * additive by construction, never touches management.json/EmbeddedIdP/
     * the setup-key flow. A no-op if no 'zitadel'-type entry is found (e.g.
     * already unwired).
     */
    protected function unwireNetbirdOidc(string $kubectl, string $ns, string $toolHost): void
    {
        $netbirdPat = $this->readClusterSecretKey($kubectl, $ns, $this->vpnName('vpn-management-secrets', $kubectl), 'pat');
        if ($netbirdPat === null) {
            return;
        }

        $providers = $this->listVpnIdentityProviders($toolHost, $netbirdPat);
        if ($providers === null) {
            return;
        }

        foreach ($providers as $provider) {
            if (($provider['type'] ?? null) === 'zitadel') {
                $this->deleteVpnIdentityProvider($toolHost, $netbirdPat, (string) ($provider['id'] ?? ''));
                break;
            }
        }
    }
}
