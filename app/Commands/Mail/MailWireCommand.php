<?php

namespace App\Commands\Mail;

use App\Data\ConfigData;
use App\Enums\ClusterTool;
use App\Exceptions\MissingFlagException;
use App\Traits\InteractsWithChat;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithMail;
use App\Traits\InteractsWithSso;
use App\Traits\InteractsWithZitadelApi;
use App\Traits\LaraKubeOutput;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\StreamsProcessOutput;
use App\Traits\SyncsClusterSecrets;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class MailWireCommand extends Command
{
    use InteractsWithChat, InteractsWithClusterContext, InteractsWithMail, InteractsWithSso, InteractsWithZitadelApi, LaraKubeOutput, RequiresFlagsWhenNonInteractive, StreamsProcessOutput, SyncsClusterSecrets;

    protected $signature = 'mail:wire
        {environment=local : Environment whose mail server to target}
        {--tool= : The tool to wire to Stalwart}
        {--engine= : Specific engine to target ("matrix")}
        {--all          : Wire every installed SMTP-capable tool}
        {--context=     : Target a specific kube-context}
        {--sender=      : Sender/login address (default: noreply@<domain>)}
        {--app-password= : Stalwart application password for the sender}
        {--remove       : Unwire SMTP mail settings from the target tool and restart it}
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

        if ($this->option('remove')) {
            return $this->unwireTargets($kubectl, $targets, $env);
        }

        if (! $this->isMailInstalled($kubectl, $ns)) {
            $this->laraKubeError('Stalwart is not installed. Run `larakube mail:init` first.');

            return 1;
        }

        $mailHost = $this->resolveMailHostReadOnly($env, $config);
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
            ClusterTool::cases(),
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
            if ($tool === null || ($tool->smtpEnv() === null && $tool !== ClusterTool::SSO)) {
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
        $cachedSender = $this->readNamedSecret($kubectl, $ns, 'mail-sender', 'sender');
        $cachedPassword = $this->readNamedSecret($kubectl, $ns, 'mail-sender', 'app-password');

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
        $this->withSpin('Caching sender credentials...', function () use ($kubectl, $ns, $sender, $appPassword, $env) {
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

            $ssoHost = $this->resolveSsoHostReadOnly($env, null);
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

        $engine = $this->resolveToolEngine($kubectl, $tool);
        $schema = $tool->smtpEnv($engine);
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

        $literals = '';
        foreach ($schema['vars'] as $key => $envName) {
            if (isset($logical[$key])) {
                $literals .= '--from-literal='.$envName.'='.escapeshellarg($logical[$key]).' ';
            }
        }

        $secret = $schema['secret'];

        $ok = true;
        $label = $engine ? "{$tool->getLabel()} ({$engine})" : $tool->getLabel();
        $this->withSpin("Wiring {$label}...", function () use ($kubectl, $ns, $secret, $literals, $deployment, $schema, &$ok) {
            Process::run(
                "{$kubectl} create secret generic {$secret} -n {$ns} {$literals}--dry-run=client -o yaml | {$kubectl} apply -f -",
            );

            $set = Process::run("{$kubectl} set env deployment/{$deployment} --from=secret/{$secret} -n {$ns}");
            $ok = $set->successful();

            if ($ok && ! empty($schema['static'])) {
                $pairs = '';
                foreach ($schema['static'] as $k => $v) {
                    $pairs .= ' '.$k.'='.escapeshellarg($v);
                }
                $ok = Process::run("{$kubectl} set env deployment/{$deployment} -n {$ns}{$pairs}")->successful();
            }

            if ($ok) {
                Process::run("{$kubectl} rollout restart deployment/{$deployment} -n {$ns}");
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

        if ($tool === ClusterTool::CHAT) {
            return $this->deploymentExists($kubectl, 'larakube-shared', 'chat-synapse');
        }

        return $this->deploymentExists($kubectl, $tool->smtpEnv()['namespace'], $tool->smtpEnv()['deployment']);
    }

    protected function resolveToolEngine(string $kubectl, ClusterTool $tool): ?string
    {
        $flag = $this->option('engine');
        if ($flag !== null) {
            return (string) $flag;
        }

        if ($tool === ClusterTool::CHAT) {
            return $this->deploymentExists($kubectl, 'larakube-shared', 'chat-synapse') ? 'matrix' : null;
        }

        return null;
    }

    protected function deploymentExists(string $kubectl, string $ns, string $deployment): bool
    {
        return trim(Process::run(
            "{$kubectl} get deployment {$deployment} -n {$ns} --no-headers --ignore-not-found",
        )->output()) !== '';
    }

    protected function readNamedSecret(string $kubectl, string $ns, string $secret, string $key): ?string
    {
        $out = trim(Process::run(
            "{$kubectl} get secret {$secret} -n {$ns} -o jsonpath='{.data.{$key}}'",
        )->output());

        return $out !== '' ? (string) base64_decode($out) : null;
    }

    protected function senderDomain(string $host): string
    {
        $parts = explode('.', $host);

        return count($parts) >= 2 ? implode('.', array_slice($parts, -2)) : $host;
    }

    /**
     * @param  array<int, ClusterTool>  $targets
     */
    protected function unwireTargets(string $kubectl, array $targets, string $env): int
    {
        if ($targets === []) {
            $this->laraKubeError('No target tool specified or installed to unwire.');

            return 1;
        }

        $unwired = [];
        foreach ($targets as $tool) {
            if ($tool === ClusterTool::SSO) {
                $ssoNs = $this->ssoNamespace();
                $pat = $this->readSsoSecret($kubectl, $ssoNs, 'machine-pat');
                $ssoHost = $this->resolveSsoHostReadOnly($env, null);
                if ($pat && $ssoHost) {
                    $this->zitadelConfigureSmtp($ssoHost, $pat, '', '', '', '', '');
                }
                $unwired[] = $tool->getLabel();

                continue;
            }

            $engine = $this->resolveToolEngine($kubectl, $tool);

            if ($tool->configuresViaConfigFile($engine)) {
                if ($this->unwireSynapseSmtp($kubectl, $tool)) {
                    $unwired[] = $tool->getLabel();
                }

                continue;
            }

            $schema = $tool->smtpEnv();
            if ($schema === null) {
                continue;
            }

            $unset = array_values($schema['vars']);
            if (! empty($schema['static'])) {
                $unset = array_merge($unset, array_keys($schema['static']));
            }
            $pairs = implode(' ', array_map(fn (string $key) => $key.'-', $unset));

            $ok = false;
            $this->withSpin("Unwiring mail from {$tool->getLabel()}...", function () use ($kubectl, $schema, $pairs, &$ok) {
                $ok = Process::run("{$kubectl} set env deployment/{$schema['deployment']} -n {$schema['namespace']} {$pairs}")->successful();
                if ($ok) {
                    Process::run("{$kubectl} rollout restart deployment/{$schema['deployment']} -n {$schema['namespace']}");
                }
            });

            if ($ok) {
                $unwired[] = $tool->getLabel();
            }
        }

        $this->laraKubeNewLine();
        foreach ($unwired as $label) {
            $this->laraKubeInfo("✅ {$label} no longer routes mail through Stalwart.");
        }

        return 0;
    }

    /**
     * Wire outbound mail into Synapse by persisting the SMTP credentials to the
     * `chat-smtp` Secret and re-rendering the homeserver.yaml Secret with an
     * `email:` block. A rollout restart is issued so Synapse picks up the new
     * config without waiting for the next pod eviction.
     */
    protected function wireSynapseSmtp(
        string $kubectl,
        ClusterTool $tool,
        array $endpoint,
        string $sender,
        string $appPassword,
        string $env,
    ): bool {
        $schema = $tool->smtpEnv('matrix');
        if ($schema === null) {
            return false;
        }

        $ns = $schema['namespace'];
        $port = $endpoint['port'];

        $smtpValues = [
            'host' => $endpoint['host'],
            'port' => $port,
            'user' => $sender,
            'password' => $appPassword,
            'from' => $sender,
        ];

        $ok = true;
        $this->withSpin('Wiring Matrix (Synapse) mail via homeserver.yaml...', function () use ($kubectl, $ns, $smtpValues, $env, &$ok) {
            // 1. Persist the SMTP credentials to the chat-smtp Secret so
            //    chat:init re-renders the email: block on re-run.
            Process::run(
                "{$kubectl} create secret generic chat-smtp -n {$ns} "
                .'--from-literal=host='.escapeshellarg($smtpValues['host']).' '
                .'--from-literal=port='.escapeshellarg((string) $smtpValues['port']).' '
                .'--from-literal=user='.escapeshellarg($smtpValues['user']).' '
                .'--from-literal=password='.escapeshellarg($smtpValues['password']).' '
                .'--from-literal=from='.escapeshellarg($smtpValues['from']).' '
                ."--dry-run=client -o yaml | {$kubectl} apply -f -",
            );

            if ($this->isOpenBaoBootstrapped($kubectl, $this->secretsNamespace())) {
                $this->pushClusterSecret($kubectl, 'SYNAPSE_SMTP_HOST', $smtpValues['host'], $env);
                $this->pushClusterSecret($kubectl, 'SYNAPSE_SMTP_PORT', (string) $smtpValues['port'], $env);
                $this->pushClusterSecret($kubectl, 'SYNAPSE_SMTP_USER', $smtpValues['user'], $env);
                $this->pushClusterSecret($kubectl, 'SYNAPSE_SMTP_PASS', $smtpValues['password'], $env);
                $this->pushClusterSecret($kubectl, 'SYNAPSE_SMTP_FROM', $smtpValues['from'], $env);
            }

            // 2. Re-render homeserver.yaml with the email: block.
            //    readChatWiredOidc reads the chat-oidc Secret to preserve any
            //    existing OIDC wiring — same read-back discipline as chat:init.
            $oidc = $this->readChatWiredOidc($kubectl, $ns);

            $raw = Process::run(
                "{$kubectl} get secret chat-synapse-config -n {$ns} -o jsonpath='{.data.homeserver\.yaml}'",
            )->output();
            if (trim($raw) === '') {
                $ok = false;

                return;
            }
            $rawYaml = (string) base64_decode(trim($raw));

            $homeserver = $this->renderSynapseConfig($rawYaml, $smtpValues, $oidc);

            $tmp = tempnam(sys_get_temp_dir(), 'synapse_cfg');
            file_put_contents($tmp, $homeserver);
            $result = Process::run(
                "{$kubectl} create secret generic chat-synapse-config -n {$ns} "
                ."--from-file=homeserver.yaml={$tmp} "
                ."--dry-run=client -o yaml | {$kubectl} apply -f -",
            );
            @unlink($tmp);

            $ok = $result->successful();
            if ($ok) {
                Process::run("{$kubectl} rollout restart deployment/chat-synapse -n {$ns}");
            }
        });

        if (! $ok) {
            $this->laraKubeError('Failed to wire Synapse mail via homeserver.yaml.');
        }

        return $ok;
    }

    /**
     * Remove the `email:` block from Synapse's homeserver.yaml Secret, then
     * delete the `chat-smtp` credential Secret and restart the pod.
     * Preserves any existing `oidc_providers:` block.
     */
    protected function unwireSynapseSmtp(string $kubectl, ClusterTool $tool): bool
    {
        $schema = $tool->smtpEnv('matrix');
        if ($schema === null) {
            return false;
        }

        $ns = $schema['namespace'];
        $ok = true;

        $this->withSpin('Unwiring Matrix (Synapse) mail from homeserver.yaml...', function () use ($kubectl, $ns, &$ok) {
            // Re-render without email: block, preserving any OIDC wiring.
            $oidc = $this->readChatWiredOidc($kubectl, $ns);

            $raw = Process::run(
                "{$kubectl} get secret chat-synapse-config -n {$ns} -o jsonpath='{.data.homeserver\.yaml}'",
            )->output();
            if (trim($raw) === '') {
                $ok = false;

                return;
            }
            $rawYaml = (string) base64_decode(trim($raw));

            $homeserver = $this->renderSynapseConfig($rawYaml, null, $oidc);

            $tmp = tempnam(sys_get_temp_dir(), 'synapse_cfg');
            file_put_contents($tmp, $homeserver);
            $result = Process::run(
                "{$kubectl} create secret generic chat-synapse-config -n {$ns} "
                ."--from-file=homeserver.yaml={$tmp} "
                ."--dry-run=client -o yaml | {$kubectl} apply -f -",
            );
            @unlink($tmp);

            $ok = $result->successful();
            if ($ok) {
                Process::run("{$kubectl} delete secret chat-smtp -n {$ns} --ignore-not-found");
                Process::run("{$kubectl} rollout restart deployment/chat-synapse -n {$ns}");
            }
        });

        return $ok;
    }
}
