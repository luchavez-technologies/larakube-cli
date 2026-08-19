<?php

namespace App\Commands\Mail;

use App\Data\ConfigData;
use App\Enums\ClusterTool;
use App\Exceptions\MissingFlagException;
use App\Traits\InteractsWithChat;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithMail;
use App\Traits\InteractsWithSso;
use App\Traits\InteractsWithToolRegistry;
use App\Traits\InteractsWithZitadelApi;
use App\Traits\LaraKubeOutput;
use App\Traits\ReconcilesPenpotFlags;
use App\Traits\RefusesUnshippedTools;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\ResolvesToolEngine;
use App\Traits\StreamsProcessOutput;
use App\Traits\SyncsClusterSecrets;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class MailWireCommand extends Command
{
    use InteractsWithChat, InteractsWithClusterContext, InteractsWithMail, InteractsWithSso, InteractsWithToolRegistry, InteractsWithZitadelApi, LaraKubeOutput, ReconcilesPenpotFlags, RefusesUnshippedTools, RequiresFlagsWhenNonInteractive, ResolvesToolEngine, StreamsProcessOutput, SyncsClusterSecrets;

    protected $signature = 'mail:wire
        {environment=local : Environment whose mail server to target}
        {--tool= : The tool to wire to Stalwart}
        {--engine= : Specific engine to target explicitly, skipping auto-detection (e.g. --engine=pocketbase)}
        {--instance= : Specific instance to target}
        {--all          : Wire every installed SMTP-capable tool}
        {--context=     : Target a specific kube-context}
        {--sender=      : Sender/login address (default: noreply@<domain>)}
        {--app-password= : Stalwart application password for the sender}
        {--forget       : Delete the cached sender credentials (mail-sender secret) and exit}';

    protected $description = 'Point a tool (n8n, …) at the Stalwart mail server for outbound email';

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');

        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;

        $context = (string) $this->option('context') ?: null;
        if (! $context && $config && $env !== 'local') {
            $context = $this->environmentContextOrCurrent($config, $env);
        }

        $kubectl = $this->mailKubectl($context);
        $ns = $this->mailNamespace();

        // Clear the cached sender BEFORE the install check — a stale cache is
        // worth clearing even if Stalwart has since been removed.
        if ($this->option('forget')) {
            Process::run("{$kubectl} delete secret mail-sender -n {$ns} --ignore-not-found");
            $this->laraKubeInfo('✅ Cleared cached sender credentials (mail-sender). Next mail:wire asks fresh.');

            return 0;
        }

        $targets = $this->resolveTargets($kubectl);

        if (! $this->isMailInstalled($kubectl, $ns)) {
            $this->laraKubeError('Stalwart is not installed. Run `larakube mail:init` first.');

            return 1;
        }

        $mailHost = $this->resolveMailHostReadOnly($env, $config, $kubectl);
        if (! $mailHost) {
            $this->laraKubeError("No Stalwart host is configured for '{$env}'. Run `larakube mail:init {$env}` first.");

            return 1;
        }

        $credentials = $this->resolveSenderCredentials($kubectl, $ns, $mailHost);
        if ($credentials === null) {
            return 1;
        }
        [$sender, $appPassword] = $credentials;

        if ($targets === []) {
            $this->laraKubeNewLine();
            $this->laraKubeInfo("✅ Verified and cached sender credentials for {$sender}.");
            $this->newLine();
            $this->line("  <fg=gray>Sender:</> <fg=blue>{$sender}</>");
            $this->line('  <fg=gray>Note:</>   No SMTP-capable tools are installed yet. Any tools installed in the future will pick these credentials up automatically.');
            $this->newLine();

            return 0;
        }

        $endpoint = $this->mailSmtpEndpoint($mailHost);
        $wired = [];

        foreach ($targets as $tool) {
            if ($this->wireTool($kubectl, $tool, $endpoint, $sender, $appPassword, $env)) {
                $wired[] = $tool->getLabel();
            }
        }

        $this->laraKubeNewLine();
        if ($wired === []) {
            $this->laraKubeError('Nothing was wired.');

            return 1;
        }

        $this->laraKubeInfo('✅ Wired to Stalwart: '.implode(', ', $wired));
        $this->newLine();
        $this->line("  <fg=gray>Sender:</>  <fg=blue>{$sender}</>   <fg=gray>via</>  <fg=blue>{$endpoint['host']}:{$endpoint['port']}</> (implicit TLS/SSL)");
        $this->newLine();

        return 0;
    }

    /**
     * SMTP-capable tools (smtpEnv() !== null or SSO) whose Deployment is installed.
     *
     * @return array<int, ClusterTool>
     */
    protected function resolveTargets(string $kubectl): array
    {
        $capable = array_filter(
            ClusterTool::shippedCases(),
            fn (ClusterTool $t) => $t->smtpEnv() !== null || $t === ClusterTool::SSO,
        );

        $installed = array_values(array_filter(
            $capable,
            fn (ClusterTool $t) => $this->isToolInstalledForMail($kubectl, $t),
        ));

        if ($this->option('all')) {
            return $installed;
        }

        $slug = $this->option('tool');
        if ($slug !== null) {
            $tool = ClusterTool::tryFrom($slug);
            if ($tool === null) {
                $this->laraKubeError("'{$slug}' is not an SMTP-capable tool.");

                return [];
            }
            if ($this->refuseUnshippedTool($tool)) {
                return [];
            }
            if ($tool->smtpEnv() === null && $tool !== ClusterTool::SSO) {
                $this->laraKubeError("'{$slug}' is not an SMTP-capable tool.");

                return [];
            }

            if (! $this->isToolInstalledForMail($kubectl, $tool)) {
                $this->laraKubeError("{$tool->getLabel()} is not installed.");

                return [];
            }

            return [$tool];
        }

        if ($installed === []) {
            return [];
        }

        $options = [];
        foreach ($installed as $tool) {
            $options[$tool->value] = $tool->getLabel();
        }

        // No --tool and no way to ask: fail with the flag name rather than
        // hanging on a prompt that will never be answered (CI, MCP, larakube proxy).
        if ($this->cannotPrompt()) {
            throw new MissingFlagException('tool', 'which tool to wire', 'larakube mail:wire production --tool=…');
        }
        $choice = select(
            label: 'Which tool would you like to wire to Stalwart?',
            options: $options,
            scroll: count($options),
        );

        return [ClusterTool::from($choice)];
    }

    /**
     * Resolve the sender address + application password, VALIDATING them against
     * Stalwart (SMTP AUTH) before use — a cached-but-stale password would
     * otherwise wire a tool with a credential that silently fails to send. On
     * failure it re-prompts (interactive) or aborts with null (scripted /
     * explicit --app-password). Only a VERIFIED pair is cached to the
     * `mail-sender` secret. Explicit --sender/--app-password override the cache;
     * `mail:wire --forget` clears it entirely.
     *
     * @return array{0: string, 1: string}|null
     */
    protected function resolveSenderCredentials(string $kubectl, string $ns, string $mailHost): ?array
    {
        $cachedSender = $this->readClusterSecretKey($kubectl, $ns, 'mail-sender', 'sender');
        $cachedPassword = $this->readClusterSecretKey($kubectl, $ns, 'mail-sender', 'app-password');

        $usingCache = $cachedSender !== null && $cachedPassword !== null
            && ! $this->option('sender') && ! $this->option('app-password');

        $sender = (string) ($this->option('sender') ?: $cachedSender ?: text(
            label: 'Sender address (a Stalwart account)',
            default: $cachedSender ?: 'noreply@'.$this->senderDomain($mailHost),
            required: true,
        ));

        $appPassword = (string) ($this->option('app-password') ?: $cachedPassword ?: password(
            label: "Stalwart application password for {$sender}",
            required: true,
        ));

        // Verify against Stalwart before wiring — never trust a cached or flagged
        // credential blindly (that's how a stale password wired a tool that then
        // silently failed to send).
        $attempts = 0;
        while (! $this->senderAuthWorks($kubectl, $ns, $sender, $appPassword)) {
            $this->laraKubeWarn(($usingCache ? "The cached password for {$sender} is stale" : "Stalwart rejected that password for {$sender}").' (SMTP AUTH failed).');

            // Scripted run or an explicit (wrong) --app-password: can't re-prompt.
            if ($this->option('no-interaction') || $this->option('app-password') || ++$attempts > 3) {
                $this->laraKubeError("Could not authenticate {$sender}. Reset the account's password with `larakube mail:password {$sender}`, then re-run — or pass it directly with --app-password=.");

                return null;
            }

            // Ask for the current password — that's the whole point: a stale cache
            // just means "type the real one" (get/reset it via `mail:password`).
            $this->line("  <fg=gray>Enter the account's current password — reset it with </><fg=blue>larakube mail:password {$sender}</><fg=gray> if you don't have it.</>");
            $usingCache = false;
            $sender = (string) text(label: 'Sender address (a Stalwart account)', default: $sender, required: true);
            $appPassword = (string) password(label: "Password for {$sender}", required: true);

            // A terminal that can't render the masked prompt returns empty — bail
            // with actionable guidance rather than looping silently.
            if ($appPassword === '') {
                $this->laraKubeError("No password captured. If your terminal isn't accepting the masked prompt, pass it directly: larakube mail:wire <tool> --env=<env> --app-password='<password>'.");

                return null;
            }
        }

        if ($usingCache) {
            $this->laraKubeInfo("Using cached sender {$sender} (run `larakube mail:wire --forget` to clear it).");
        }

        // Cache only a VERIFIED pair.
        $env = (string) $this->argument('environment');
        $this->withSpin('Caching sender credentials...', function () use ($kubectl, $ns, $sender, $appPassword, $env): void {
            Process::run(
                "{$kubectl} create secret generic mail-sender -n {$ns} "
                .'--from-literal=sender='.escapeshellarg($sender).' '
                .'--from-literal=app-password='.escapeshellarg($appPassword).' '
                ."--dry-run=client -o yaml | {$kubectl} apply -f -",
            );

            if ($this->isOpenBaoBootstrapped($kubectl, $this->secretsNamespace())) {
                $this->pushClusterSecret($kubectl, 'STALWART_MAIL_SENDER', $sender, $env);
                $this->pushClusterSecret($kubectl, 'STALWART_MAIL_PASSWORD', $appPassword, $env);
            }
        });

        return [$sender, $appPassword];
    }

    /**
     * Verify a sender + password can authenticate to Stalwart, by driving an
     * SMTP AUTH LOGIN against the submissions port (465, implicit TLS) from
     * inside the pod — no dependency on the operator's network reaching 465.
     */
    protected function senderAuthWorks(string $kubectl, string $ns, string $sender, string $password): bool
    {
        if ($sender === '' || $password === '') {
            return false;
        }

        $convo = "EHLO larakube\r\nAUTH LOGIN\r\n".base64_encode($sender)."\r\n".base64_encode($password)."\r\nQUIT\r\n";
        // NO -crlf: the convo already uses \r\n; -crlf would double it to \r\r\n,
        // which strict servers reject (RFC 2821 §2.7.1).
        $script = 'echo '.base64_encode($convo).' | base64 -d | openssl s_client -quiet -connect 127.0.0.1:465 2>/dev/null';
        $out = Process::timeout(30)->run(
            "{$kubectl} exec deploy/stalwart -n {$ns} -- sh -c ".escapeshellarg($script),
        )->output();

        return str_contains($out, '235'); // 235 = authentication accepted
    }

    /**
     * @param  array{host: string, port: string}  $endpoint
     */
    protected function wireTool(string $kubectl, ClusterTool $tool, array $endpoint, string $sender, string $appPassword, string $env): bool
    {
        if ($tool === ClusterTool::SSO) {
            $pat = $this->readSsoSecret($kubectl, $this->ssoNamespace(), 'machine-pat');
            if ($pat === null) {
                $this->laraKubeError('Could not read Zitadel automation PAT. Ensure sso:init has completed.');

                return false;
            }

            $ssoHost = $this->resolveSsoHostReadOnly($env, null, $kubectl);
            if ($ssoHost === null) {
                $this->laraKubeError("Could not resolve Zitadel's host for '{$env}'. Re-run `larakube sso:init {$env}` so the host is persisted.");

                return false;
            }

            $ok = false;
            $this->withSpin('Wiring Identity Provider / SSO (Zitadel)...', function () use ($ssoHost, $pat, $endpoint, $sender, $appPassword, &$ok) {
                $ok = $this->zitadelConfigureSmtp(
                    $ssoHost,
                    $pat,
                    $endpoint['host'].':'.$endpoint['port'],
                    $sender,
                    $appPassword,
                    $sender,
                    'Zitadel',
                );

                return $ok;
            });

            return $ok;
        }

        $instance = $this->resolveWireInstance($kubectl, $tool);
        $engine = $this->resolveInstanceEngine($kubectl, $tool, $instance, $this->option('engine'));
        $schema = $tool->smtpEnv($engine, $instance);
        if ($schema === null) {
            return false;
        }

        if ($tool->configuresViaConfigFile($engine)) {
            return $this->wireSynapseSmtp($kubectl, $tool, $endpoint, $sender, $appPassword, $env);
        }
        $deployment = $schema['deployment'];
        $ns = $schema['namespace'];

        $logical = [
            'host' => $endpoint['host'],
            'port' => $endpoint['port'],
            'user' => $sender,
            'password' => $appPassword,
            'from' => $sender,
            'secure' => 'false',
        ];

        // Grafana combines host & port into a single GF_SMTP_HOST=host:port
        if ($tool === ClusterTool::MONITOR) {
            $logical['host'] = $endpoint['host'].':'.$endpoint['port'];
        }

        // GlitchTip consumes one composed django-environ URL (EMAIL_URL), not
        // per-host/port/user vars. Stalwart talks implicit TLS on 465, hence
        // smtp+ssl://, and the credentials must be percent-encoded (the
        // sender is an email address — an unencoded @ would break the URL).
        if ($tool === ClusterTool::ERRORS) {
            $logical['email_url'] = 'smtp+ssl://'.rawurlencode($logical['user']).':'.rawurlencode($logical['password']).'@'.$logical['host'].':'.$logical['port'];
        }

        $staticVars = $schema['static'] ?? [];
        $isPenpot = str_starts_with($deployment, 'design-penpot-backend');
        // Instance suffix (e.g. '-design-luchtech-dev', or '' for the bare
        // legacy name) — derived from the deployment name so the oidc secret
        // and frontend deployment names below always match the same instance
        // $schema['secret']/$deployment already resolved to.
        $penpotSuffix = $isPenpot ? substr($deployment, strlen('design-penpot-backend')) : '';

        // PENPOT_FLAGS is reconciled from scratch by ReconcilesPenpotFlags,
        // not carried through the generic static-var plumbing below — see
        // docs/decisions/0013-design-init-idempotent-flags.md. Computed
        // AFTER the secret is (re)written below, not here — see the matching
        // comment in SsoWireCommand::applyToolEnv() (confirmed live
        // 2026-08-17, Design) for why computing it before the write is wrong.
        if ($isPenpot) {
            unset($staticVars['PENPOT_FLAGS']);
        }

        $literals = '';
        foreach ($staticVars as $envName => $value) {
            $literals .= '--from-literal='.$envName.'='.escapeshellarg($value).' ';
        }
        foreach ($schema['vars'] as $key => $envName) {
            if (isset($logical[$key])) {
                $literals .= '--from-literal='.$envName.'='.escapeshellarg($logical[$key]).' ';
            }
        }

        $secret = $schema['secret'];

        $ok = true;
        $label = $engine ? "{$tool->getLabel()} ({$engine})" : $tool->getLabel();
        $this->withSpin("Wiring {$label}...", function () use ($kubectl, $ns, $secret, $literals, $deployment, $schema, $isPenpot, $penpotSuffix, &$ok): void {
            Process::run(
                "{$kubectl} create secret generic {$secret} -n {$ns} {$literals}--dry-run=client -o yaml | {$kubectl} apply -f -",
            );

            $set = Process::run("{$kubectl} set env deployment/{$deployment} --from=secret/{$secret} -n {$ns}");
            $ok = $set->successful();

            // ADR 0018: $staticVars is already in the Secret (see $literals
            // above) and already applied declaratively via --from=secret —
            // a second literal `kubectl set env KEY=value` pass here would
            // desync kubectl apply's bookkeeping for every future
            // `{tool}:init` re-run. Mail wiring has no sso_only_vars
            // concept, so there's no legitimate unset case left either.
            if ($ok) {
                Process::run("{$kubectl} rollout restart deployment/{$deployment} -n {$ns}");
                $this->forceExternalSecretReconcile($kubectl, $ns, $secret);
            }

            if ($ok && $isPenpot) {
                $penpotFlags = $this->resolveDesignPenpotFlags($kubectl, $ns, "design-oidc{$penpotSuffix}", $secret, null, $deployment);
                $this->applyDesignPenpotFlags($kubectl, $ns, "design-oidc{$penpotSuffix}", $penpotFlags, $deployment, "design-penpot-frontend{$penpotSuffix}");
            }

            // Secondary components that share the PRIMARY's wiring secret
            // (e.g. GlitchTip's worker, which sends the alert emails) get
            // the same envFrom + restart — the general form of Penpot's
            // frontend needing the same OIDC client as its backend.
            foreach ($schema['also_patch'] ?? [] as $secondaryDeployment) {
                if (! $ok) {
                    break;
                }
                $ok = Process::run("{$kubectl} set env deployment/{$secondaryDeployment} --from=secret/{$secret} -n {$ns}")->successful();
                if ($ok) {
                    Process::run("{$kubectl} rollout restart deployment/{$secondaryDeployment} -n {$ns}");
                }
            }
        });

        if (! $ok) {
            $this->laraKubeError("Failed to wire {$label}.");
        }

        return $ok;
    }

    protected function isToolInstalledForMail(string $kubectl, ClusterTool $tool): bool
    {
        if ($tool === ClusterTool::SSO) {
            return $this->isSsoInstalled($kubectl, $this->ssoNamespace());
        }

        $instance = $this->resolveWireInstance($kubectl, $tool);

        // Resolve the live engine BEFORE checking existence — a tool whose
        // smtpEnv() shape depends on $engine (DATA) would otherwise always
        // be checked against its DEFAULT engine's Deployment name, so a
        // PocketBase-only install (data-directus doesn't exist) was
        // silently invisible to --all/the tool-selection prompt.
        $engine = $tool->engines() !== [] ? $this->resolveInstanceEngine($kubectl, $tool, $instance, (string) ($this->option('engine') ?: '') ?: null) : null;
        $schema = $tool->smtpEnv($engine, $instance);
        if ($schema === null) {
            return false;
        }

        return $this->deploymentExists($kubectl, $schema['namespace'], $schema['deployment']);
    }

    /**
     * Which instance a tool's SMTP wiring targets. --instance= always wins.
     * Otherwise: conventional single-instance tools (the vast majority)
     * ignore whatever string lands here entirely — every smtpEnv()
     * implementation except CRM's hardcodes its deployment name — so 'main'
     * is a safe default for them. Host-derived, no-'main' tools (CRM, and
     * DATA once data:init registers correctly) have no 'main' deployment at
     * all; for those, the registry's real instance is the only name that
     * will ever match a live Deployment. Multiple registered instances is
     * genuinely ambiguous and needs an explicit --instance=, same as every
     * other multi-instance command.
     */
    protected function resolveWireInstance(string $kubectl, ClusterTool $tool): string
    {
        $explicit = (string) ($this->option('instance') ?: '');
        if ($explicit !== '') {
            return $explicit;
        }

        $registered = $this->getToolInstances($kubectl, $tool);

        return count($registered) === 1 ? $registered[0] : 'main';
    }

    protected function deploymentExists(string $kubectl, string $ns, string $deployment): bool
    {
        return trim(Process::run(
            "{$kubectl} get deployment {$deployment} -n {$ns} --no-headers --ignore-not-found",
        )->output()) !== '';
    }

    protected function senderDomain(string $host): string
    {
        $parts = explode('.', $host);

        return count($parts) >= 2 ? implode('.', array_slice($parts, -2)) : $host;
    }
}
