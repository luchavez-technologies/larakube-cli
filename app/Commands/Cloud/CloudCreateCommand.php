<?php

namespace App\Commands\Cloud;

use App\Data\ConfigData;
use App\Data\StackData;
use App\Enums\ManagedProvider;
use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithOpenTofu;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ProvisionsK3sNode;
use App\Traits\ResolvesEnvironmentContext;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

/**
 * Provision real infrastructure with OpenTofu, then hand off to the existing
 * single-node k3s pipeline (VPS) or DOKS flow (managed). A "stack" is one droplet
 * or one cluster, registered globally so multiple environments/projects can share
 * it — `cloud:create <env>` either creates a new stack or attaches the env to an
 * existing one.
 */
class CloudCreateCommand extends Command
{
    use InteractsWithEnvironments, InteractsWithOpenTofu, InteractsWithProjectConfig, LaraKubeOutput, ProvisionsK3sNode, ResolvesEnvironmentContext;

    /** Available providers we can provision (add AWS/GCP later as template dirs appear). */
    private const PROVIDERS = [
        'do' => 'DigitalOcean',
        // 'aws' => 'Amazon Web Services',
        // 'gcp' => 'Google Cloud Platform',
    ];

    protected $signature = 'cloud:create
        {--provider= : Cloud provider slug (do, aws, …). Default or prompted.}
        {--vps : Create a VPS / droplet (SSH + k3s, single-node)}
        {--managed : Create a managed Kubernetes cluster}
        {environment? : Inside a project, the environment to bind to this stack. Outside one, used as the stack name.}';

    protected $description = 'Provision infrastructure with OpenTofu — provider, then VPS or managed k8s';

    /**
     * Backward-compatible alias for those who prefer the shorthand.
     *
     * @var array<int, string>
     */
    protected $aliases = ['cloud:new'];

    public function handle(): int
    {
        $this->renderHeader();
        $this->laraKubeInfo('LaraKube Cloud Pilot: OpenTofu Provisioner');
        $this->newLine();

        // 1. Resolve provider (prompt → default → flag).
        $provider = $this->resolveProvider();
        if (! $provider) {
            return 1;
        }

        // 2. Resolve target kind (vps vs managed).
        $targetKind = $this->resolveTargetKind();
        if (! $targetKind) {
            return 1;
        }

        // 3. Tooling prerequisites: token + tofu/terraform binary.
        if (! $this->ensureProviderToken($provider)) {
            return 1;
        }
        $bin = $this->ensureTofu();
        if (! $bin) {
            return 1;
        }
        $this->line('  <fg=gray>Using:</> <fg=cyan>'.$bin['path'].'</> '.($bin['isOpenTofu'] ? '(OpenTofu — encrypted state)' : '(Terraform — plaintext state)'));
        $this->newLine();

        [$config, $projectPath, $environment, $standaloneName] = $this->resolveEnvironment($this->argument('environment'));
        $nameBase = $config?->getName() ?? $standaloneName;

        // Attach to an existing compatible stack, or create a new one? Defaults
        // to "attach" when this project/name already has a stack of this exact
        // kind — a stack is meant to be reused across every environment of a
        // project (see StackData's docblock), so that's virtually always what's
        // wanted once one already exists. Still a real confirm either way, never
        // auto-skipped.
        $existing = $this->stacksOfKind($targetKind);
        if (! empty($existing)) {
            $expectedStack = $this->findExpectedStack($nameBase, $targetKind);

            $prompt = $expectedStack
                ? "Attach this environment to your existing '{$expectedStack->name}' {$targetKind} stack instead of creating a new one?"
                : "Attach to an existing {$targetKind} stack instead of creating a new one?";

            if (confirm($prompt, default: $expectedStack !== null)) {
                return $this->attachToExisting($existing, $targetKind, $provider, $config, $projectPath, $environment, $expectedStack);
            }
        }

        return $targetKind === 'vps'
            ? $this->createVps($bin, $provider, $config, $projectPath, $environment, $nameBase)
            : $this->createManaged($bin, $provider, $config, $projectPath, $environment, $nameBase);
    }

    /**
     * Resolve the optional project + environment to bind. Standalone (no project)
     * is allowed — you just provision infra without wiring an env to it. $rawArgument
     * becomes the standalone stack-name base in that case instead of being
     * discarded — see promptStackName(). Takes it as a parameter (rather than
     * reading $this->argument() internally) so this is callable without a bound
     * console input.
     *
     * @return array{0: ?ConfigData, 1: ?string, 2: ?string, 3: ?string}
     */
    protected function resolveEnvironment(?string $rawArgument): array
    {
        if (! $this->isLaraKubeProject()) {
            $this->line('  <fg=gray>Not in a LaraKube project — provisioning infra only (no environment binding).</>');
            $this->newLine();

            return [null, null, null, $rawArgument];
        }

        $projectPath = getcwd();
        $config = $this->getProjectConfigObject($projectPath);
        $environment = $rawArgument ?: $this->askForCloudEnvironment(
            label: 'Which environment should deploy to this stack?',
        );

        return [$config, $projectPath, $environment, null];
    }

    /** Registered stacks matching a kind (vps/managed). @return array<string, StackData> */
    protected function stacksOfKind(string $kind): array
    {
        return array_filter(
            $this->getGlobalConfig()->getStacks(),
            fn (StackData $s) => $s->kind === $kind,
        );
    }

    /**
     * The stack this environment would attach to by default under the
     * deterministic "{name}-{kind}" naming convention, if one's already
     * registered. Used to default the create-vs-attach confirm to "attach"
     * without ever skipping it outright — see handle().
     */
    protected function findExpectedStack(?string $nameBase, string $targetKind): ?StackData
    {
        if (! $nameBase) {
            return null;
        }

        return $this->getGlobalConfig()->findStack($this->slug($nameBase.'-'.$targetKind));
    }

    protected function registerStack(string $name, string $kind, ?string $region, ?string $ip, ?string $context, ?ConfigData $config, ?string $environment): void
    {
        $stack = new StackData(
            name: $name,
            provider: 'do',
            kind: $kind,
            region: $region,
            context: $context,
            ip: $ip,
            createdAt: gmdate('c'),
        );
        if ($config && $environment) {
            $stack->bind($config->getName(), $environment);
        }
        $this->putStack($stack);
    }

    /** Persist a stack into the global registry (single load+save). */
    protected function putStack(StackData $stack): void
    {
        $config = $this->getGlobalConfig();
        $config->putStack($stack);
        $config->save();
    }

    /**
     * Default stack name is always "{name}-{kind}" — never an environment. A
     * stack is explicitly meant to be reused across every environment of a
     * project (see StackData's docblock), so baking one env into the name
     * would misrepresent it the moment a second environment attaches. $kind
     * is always 'vps' or 'managed' — the two fundamental provisioning models
     * this registry distinguishes; which specific managed flavor (DOKS,
     * later EKS/GKE) is tracked separately via StackData::$provider.
     */
    protected function promptStackName(?string $nameBase, string $kind): string
    {
        $base = ($nameBase !== null && $nameBase !== '' ? $nameBase : 'standalone').'-'.$kind;
        $default = $this->slug($base);

        return $this->slug(text(label: 'Stack name', default: $default, hint: 'Also the Tofu workdir name under ~/.larakube/tofu/'));
    }

    /** Resolve which provider to provision for. */
    private function resolveProvider(): ?string
    {
        $flag = $this->option('provider');
        if ($flag && ! isset(self::PROVIDERS[$flag])) {
            $this->laraKubeError("Unknown provider: '{$flag}'. Supported: ".implode(', ', array_keys(self::PROVIDERS)));

            return null;
        }
        if ($flag) {
            return $flag;
        }

        // Prompt unless a default is set globally.
        $default = $this->getDefaultCloudProvider();
        if ($default && isset(self::PROVIDERS[$default]) && confirm("Use {$default} (".self::PROVIDERS[$default].') as the provider?', true)) {
            return $default;
        }

        return select(
            label: 'Which cloud provider?',
            options: collect(self::PROVIDERS)->map(fn (string $label, string $slug) => [$slug => $label])->collapse()->all(),
        );
    }

    /** Resolve vps vs managed kind. */
    private function resolveTargetKind(): ?string
    {
        if ($this->option('vps') && $this->option('managed')) {
            $this->laraKubeError('Use --vps or --managed, not both.');

            return null;
        }
        if ($this->option('vps')) {
            return 'vps';
        }
        if ($this->option('managed')) {
            return 'managed';
        }

        return select(
            label: 'What kind of infrastructure?',
            options: [
                'vps' => 'VPS / droplet (SSH + k3s, single-node)',
                'managed' => 'Managed Kubernetes (multi-node, provider-managed control plane)',
            ],
        );
    }

    /** Ensure we have a valid API token for the chosen provider. */
    private function ensureProviderToken(string $provider): bool
    {
        return match ($provider) {
            'do' => $this->ensureDoToken(),
            default => true, // future providers implement their own ensure method
        };
    }

    /** Prompt for + persist the DO API token if we don't have one yet. */
    private function ensureDoToken(): bool
    {
        if ($this->getDoToken()) {
            return true;
        }

        $this->laraKubeWarn('No DigitalOcean API token found.');
        $this->line('  <fg=gray>Create one at</> https://cloud.digitalocean.com/account/api/tokens <fg=gray>(read + write).</>');
        $token = text(
            label: 'Paste your DigitalOcean API token',
            required: true,
            hint: 'Stored in ~/.larakube and passed to OpenTofu via TF_VAR_do_token (never written into HCL).',
        );
        $this->setDoToken($token);
        $this->laraKubeInfo('Saved DO token to your global LaraKube config.');

        return true;
    }

    /** Map a provider slug to its ManagedProvider enum (DOKS, EKS, …). */
    private function resolveManagedProvider(string $provider): ManagedProvider
    {
        return match ($provider) {
            'do' => ManagedProvider::DOKS,
            'aws' => ManagedProvider::EKS,
            'gcp' => ManagedProvider::GKE,
            default => ManagedProvider::CUSTOM,
        };
    }

    /**
     * Attach an environment to an already-provisioned stack — no apply. The env's
     * deploy target becomes that stack's context (managed) or IP (VPS). $preferred
     * (this project's exact-name match, if any) is pre-selected but not forced —
     * the picker still shows every compatible stack.
     *
     * @param  array<string, StackData>  $existing
     */
    private function attachToExisting(array $existing, string $target, string $provider, ?ConfigData $config, ?string $projectPath, ?string $environment, ?StackData $preferred = null): int
    {
        $options = [];
        foreach ($existing as $s) {
            $options[$s->name] = $s->name.'  ('.($s->region ?? '?').($s->ip ? ', '.$s->ip : '').', ctx: '.($s->context ?? '?').')';
        }
        $name = select(label: 'Attach to which stack?', options: $options, default: $preferred?->name);
        $stack = $existing[$name];

        if (! $config || ! $environment) {
            $this->laraKubeWarn('No project/environment to bind — nothing to do. (Run inside a project to attach an env.)');

            return 0;
        }

        if ($target === 'vps') {
            $this->bindVpsEnv($config, $projectPath, $environment, $stack->ip, 'larakube', '22', $this->defaultKeyPath());
        } else {
            $managedProvider = $this->resolveManagedProvider($provider);
            $this->recordManagedTarget($config, $environment, $projectPath, $stack->context, $managedProvider);
        }

        $stack->bind($config->getName(), $environment);
        $this->putStack($stack);

        $this->newLine();
        $this->laraKubeInfo("✅ '{$environment}' now deploys to stack '{$stack->name}' (namespace: ".$config->getNamespace($environment).').');
        $this->line('  <fg=gray>Co-tenancy is namespace-isolated; Traefik is shared and not re-installed.</>');

        return 0;
    }

    /** Provision a new droplet/VM, then run the k3s + hardening pipeline. */
    private function createVps(array $bin, string $provider, ?ConfigData $config, ?string $projectPath, ?string $environment, ?string $nameBase): int
    {
        $this->laraKubeWarn('Recommended: 1GB RAM minimum for stable K3s deployments.');
        $this->newLine();

        $keyPath = $this->promptKeyPath();
        if (! $keyPath) {
            return 1;
        }
        $pubKey = $this->readPublicKey($keyPath);
        if (! $pubKey) {
            return 1;
        }
        $fingerprint = $this->sshKeyFingerprint($keyPath.'.pub');
        if (! $fingerprint) {
            $this->laraKubeError('Could not compute the SSH key fingerprint (is ssh-keygen installed?).');

            return 1;
        }

        $stackName = $this->promptStackName($nameBase, 'vps');
        $region = $this->promptRegion();
        $size = text(label: 'Droplet size slug', default: 's-1vcpu-1gb', hint: 'e.g. s-1vcpu-1gb, s-2vcpu-2gb');
        $adminCidr = $this->promptAdminCidr();

        // Restrict SSH + the k3s API to the admin CIDR when given; else open.
        $sources = $adminCidr ? '"'.$adminCidr.'"' : '"0.0.0.0/0", "::/0"';

        $hcl = view("tofu.{$provider}.vps", [
            'region' => $region,
            'size' => $size,
            'dropletName' => $stackName,
            'sshKeyName' => $stackName,
            'sshPubKey' => $pubKey,
            'keyFingerprint' => $fingerprint,
            'sshSources' => $sources,
            'apiSources' => $sources,
        ])->render();
        $this->writeTofuFiles($stackName, ['main.tf' => $hcl]);

        if (! $this->applyStack($bin, $stackName, "droplet '{$stackName}' in {$region} ({$size})")) {
            return 1;
        }

        $ip = $this->tofuOutput($bin, $stackName, 'ip');
        if (! $ip) {
            $this->laraKubeError('Provisioned, but could not read the droplet IP from Tofu outputs.');

            return 1;
        }
        $this->laraKubeInfo("✅ Droplet ready at <fg=cyan>{$ip}</>");

        // Register the stack now (before the long provisioning run) so a later
        // failure still leaves a destroyable record.
        $this->registerStack($stackName, 'vps', $region, $ip, null, $config, $environment);

        // Wait for sshd, then run the shared single-node pipeline as root.
        if (! $this->waitForSsh('root', $ip, '22', $keyPath)) {
            $this->laraKubeError("SSH never came up at root@{$ip}. The droplet exists — re-run provisioning once it's reachable.");

            return 1;
        }

        $pipelineConfig = $config ?? $this->getProjectConfigObject(getcwd());
        $context = $this->provisionK3sNode('root', $ip, '22', $keyPath, $pipelineConfig);

        // Record the resolved context on the stack + bind the env.
        $this->updateStackContext($stackName, $context);
        if ($config && $environment) {
            $this->bindVpsEnv($config, $projectPath, $environment, $ip, 'larakube', '22', $keyPath);
            $this->tagBinding($stackName, $config->getName(), $environment);
        }

        $this->newLine();
        $this->laraKubeInfo('✅ VPS provisioning complete!');
        $this->printVpsNextSteps($context, $environment);

        return 0;
    }

    /** Provision a new managed cluster, merge its kubeconfig, then install Traefik. */
    private function createManaged(array $bin, string $provider, ?ConfigData $config, ?string $projectPath, ?string $environment, ?string $nameBase): int
    {
        $stackName = $this->promptStackName($nameBase, 'managed');
        $region = $this->promptRegion();
        $size = text(label: 'Node size slug', default: 's-2vcpu-4gb', hint: 'e.g. s-2vcpu-4gb');
        $nodeCount = (int) text(label: 'Node count', default: '2', validate: fn ($v) => ((int) $v) >= 1 ? null : 'At least 1 node.');
        $versionPrefix = text(label: 'Kubernetes minor version prefix', default: '', hint: 'e.g. "1.31." — blank = latest available');

        $hcl = view("tofu.{$provider}.managed", [
            'region' => $region,
            'clusterName' => $stackName,
            'size' => $size,
            'nodeCount' => $nodeCount,
            'versionPrefix' => $versionPrefix,
        ])->render();
        $this->writeTofuFiles($stackName, ['main.tf' => $hcl]);

        if (! $this->applyStack($bin, $stackName, "DOKS cluster '{$stackName}' in {$region} ({$nodeCount}× {$size})")) {
            return 1;
        }

        $kubeconfig = $this->tofuOutput($bin, $stackName, 'kubeconfig');
        $context = $this->tofuOutput($bin, $stackName, 'context');
        if (! $kubeconfig || ! $context) {
            $this->laraKubeError('Provisioned, but could not read kubeconfig/context from Tofu outputs.');

            return 1;
        }

        $this->mergeKubeconfig($kubeconfig);
        $this->laraKubeInfo("✅ Cluster ready. Context: <fg=cyan>{$context}</>");
        $this->registerStack($stackName, 'managed', $region, null, $context, $config, $environment);

        // Traefik + Let's Encrypt via the existing managed flow (idempotent).
        $this->newLine();
        $this->laraKubeInfo('Installing Traefik + Let\'s Encrypt via cloud:init:doks...');
        $this->call('cloud:init:doks', ['--context' => $context]);

        if ($config && $environment) {
            $managedProvider = $this->resolveManagedProvider($provider);
            $this->recordManagedTarget($config, $environment, $projectPath, $context, $managedProvider);
            $this->tagBinding($stackName, $config->getName(), $environment);
        }

        $this->newLine();
        $this->laraKubeInfo('✅ Managed k8s provisioning complete!');
        $this->line('  <fg=gray>Hardening follow-up:</> restrict the cluster\'s kube-API to your IP and add default-deny NetworkPolicies.');

        return 0;
    }

    // --- shared helpers -----------------------------------------------------

    /** `tofu init` + a confirmed `tofu apply`. */
    private function applyStack(array $bin, string $stack, string $what): bool
    {
        $this->laraKubeInfo('Initializing OpenTofu (downloading the DigitalOcean provider)...');
        if (! $this->tofuInit($bin, $stack)) {
            $this->laraKubeError('tofu init failed.');

            return false;
        }

        $this->newLine();
        if (! confirm("Apply now — this creates {$what} on DigitalOcean (real resources, real cost)?", true)) {
            $this->laraKubeInfo('Cancelled. (Tofu files are saved; re-run to apply.)');

            return false;
        }

        $this->laraKubeInfo('Applying...');
        if (! $this->tofuApply($bin, $stack)) {
            $this->laraKubeError('tofu apply failed.');

            return false;
        }

        return true;
    }

    private function updateStackContext(string $name, string $context): void
    {
        if ($stack = $this->getGlobalConfig()->findStack($name)) {
            $stack->context = $context;
            $this->putStack($stack);
        }
    }

    private function tagBinding(string $name, string $appName, string $environment): void
    {
        if ($stack = $this->getGlobalConfig()->findStack($name)) {
            $stack->bind($appName, $environment);
            $this->putStack($stack);
        }
    }

    /** Record a VPS deploy target (ip + SSH) on an environment's cloud config. */
    private function bindVpsEnv(ConfigData $config, string $projectPath, string $environment, ?string $ip, string $user, string $port, string $keyPath): void
    {
        $data = $config->toArray();
        $data['environments'][$environment]['cloud'] = [
            'ip' => $ip,
            'user' => $user,
            'port' => (int) $port,
            'key' => $keyPath,
        ];
        ConfigData::from($data)->saveToFile($projectPath);
        $this->laraKubeInfo("Bound '{$environment}' to {$ip} (saved to .larakube.local.json).");
    }

    /** Merge a raw kubeconfig YAML into ~/.kube/config using kubectl's flatten. */
    private function mergeKubeconfig(string $rawYaml): void
    {
        $local = home_path('.kube/config');
        if (! is_dir(home_path('.kube'))) {
            @mkdir(home_path('.kube'), 0700, true);
        }
        if (file_exists($local)) {
            copy($local, home_path('.kube/config.bak.'.time()));
        }

        $tmp = tempnam(sys_get_temp_dir(), 'doks_kube');
        file_put_contents($tmp, $rawYaml);

        if (file_exists($local) && filesize($local) > 0) {
            $merged = Process::run('KUBECONFIG='.escapeshellarg($local).':'.escapeshellarg($tmp).' kubectl config view --flatten')->output();
            if ($merged !== '') {
                file_put_contents($local, $merged);
            } else {
                $this->laraKubeError('Failed to merge kubeconfig — left local config untouched.');
            }
        } else {
            copy($tmp, $local);
        }
        @unlink($tmp);
    }

    // --- prompts ------------------------------------------------------------

    private function promptKeyPath(): ?string
    {
        $keyPath = text(label: 'Path to your SSH Private Key', default: home_path('.ssh/id_rsa'));
        $keyPath = str_replace('~', home_path(), $keyPath);

        if (! file_exists($keyPath)) {
            $this->laraKubeError("SSH key not found at: {$keyPath}");

            return null;
        }

        return $keyPath;
    }

    private function defaultKeyPath(): string
    {
        return home_path('.ssh/id_rsa');
    }

    /**
     * The DigitalOcean SSH key fingerprint (MD5, colon-hex) for a public key file,
     * computed locally via ssh-keygen. DO identifies keys by this fingerprint, so
     * we can reference an already-uploaded key without re-creating it.
     */
    private function sshKeyFingerprint(string $pubKeyPath): ?string
    {
        $out = trim(Process::run('ssh-keygen -l -E md5 -f '.escapeshellarg($pubKeyPath))->output());
        // Format: "256 MD5:3b:16:..:cc comment (ED25519)"
        if (preg_match('/MD5:([0-9a-f:]+)/i', $out, $m)) {
            return strtolower($m[1]);
        }

        return null;
    }

    private function readPublicKey(string $keyPath): ?string
    {
        $pub = $keyPath.'.pub';
        if (! file_exists($pub)) {
            $this->laraKubeError("Public key not found at {$pub} — needed to authorize the droplet. Generate one with ssh-keygen.");

            return null;
        }

        return trim((string) file_get_contents($pub));
    }

    private function promptRegion(): string
    {
        return text(
            label: 'DigitalOcean region slug',
            default: 'nyc1',
            hint: 'e.g. nyc1, sfo3, ams3, sgp1, lon1, fra1, blr1, syd1',
        );
    }

    private function promptAdminCidr(): ?string
    {
        if (! confirm('Restrict SSH + the k3s API (6443) to a single admin IP? (recommended)', false)) {
            return null;
        }
        $ip = text(label: 'Admin IPv4 (your current public IP)', required: true, hint: 'A /32 is appended automatically.');

        return rtrim($ip).'/32';
    }

    private function slug(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9-]+/', '-', strtolower($value)), '-');
    }

    private function printVpsNextSteps(string $context, ?string $environment): void
    {
        $this->line('  <fg=green>Next steps:</>');
        $this->line("    <fg=yellow>kubectl config use-context {$context}</>");
        if ($environment) {
            $this->line("    <fg=yellow>larakube cloud:configure {$environment} --only=registry</>  <fg=gray># container registry</>");
            $this->line("    <fg=yellow>larakube cloud:configure {$environment} --only=ci</>       <fg=gray># CI secrets (.env + scoped kubeconfig)</>");
            $this->line("    <fg=yellow>larakube cloud:deploy {$environment}</>");
        }
        $this->newLine();
    }
}
