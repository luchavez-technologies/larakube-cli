<?php

namespace App\Commands\Mail;

use App\Data\ConfigData;
use App\Enums\ClusterTool;
use App\Traits\InteractsWithChat;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithMail;
use App\Traits\InteractsWithSso;
use App\Traits\InteractsWithToolRegistry;
use App\Traits\InteractsWithZitadelApi;
use App\Traits\LaraKubeOutput;
use App\Traits\RefusesUnshippedTools;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\ResolvesToolEngine;
use App\Traits\ResolvesToolHost;
use App\Traits\StreamsProcessOutput;
use App\Traits\SyncsClusterSecrets;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;
use Spatie\TemporaryDirectory\TemporaryDirectory;

class MailUnwireCommand extends Command
{
    use InteractsWithChat, InteractsWithClusterContext, InteractsWithMail, InteractsWithSso, InteractsWithToolRegistry, InteractsWithZitadelApi, LaraKubeOutput, RefusesUnshippedTools, RequiresFlagsWhenNonInteractive, ResolvesToolEngine, ResolvesToolHost, StreamsProcessOutput, SyncsClusterSecrets;

    protected $signature = 'mail:unwire
        {environment=local : Environment whose mail settings to unwire}
        {--tool= : The tool to unwire from Stalwart}
        {--domain= : The instance to target (e.g. --domain=blog.example.com). Omit for the default instance. Ignored with --all}
        {--engine= : Specific engine to target explicitly, skipping auto-detection (e.g. --engine=pocketbase)}
        {--all   : Unwire every installed SMTP-capable tool}
        {--context= : Target a specific kube-context}';

    protected $description = 'Unwire SMTP mail settings from a tool and restart its deployment';

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
        $targets = $this->resolveTargets($kubectl);

        return $this->unwireTargets($kubectl, $targets, $env);
    }

    protected function resolveTargets(string $kubectl): array
    {
        $option = (string) ($this->option('tool') ?? '');
        if ($option !== '') {
            $tool = ClusterTool::tryFrom($option);
            if ($tool === null) {
                $this->laraKubeError("Unknown tool '{$option}'.");

                return [];
            }
            if ($this->refuseUnshippedTool($tool)) {
                return [];
            }

            return [$tool];
        }

        if ($this->option('all')) {
            $installed = [];
            foreach (ClusterTool::shippedCases() as $candidate) {
                // hasMailWire() is the pair's marker contract (HasSmtpWiring),
                // and it already folds in SSO -- Zitadel takes SMTP through its
                // own API rather than deployment env, so it has no smtpEnv()
                // schema but is still mail-wireable. That special case used to
                // be spelled out at every call site.
                if (! $candidate->hasMailWire()) {
                    continue;
                }
                if ($this->isToolInstalledForMail($kubectl, $candidate)) {
                    $installed[] = $candidate;
                }
            }

            return $installed;
        }

        if ($this->option('no-interaction')) {
            $this->laraKubeError('Passing --tool or --all is required when running in non-interactive mode.');

            return [];
        }

        $options = [];
        foreach (ClusterTool::shippedCases() as $candidate) {
            if ($candidate->hasMailWire() && $this->isToolInstalledForMail($kubectl, $candidate)) {
                $options[$candidate->value] = $candidate->getLabel();
            }
        }

        if ($options === []) {
            $this->laraKubeWarn('No SMTP-capable tools are currently installed.');

            return [];
        }

        $selected = \Laravel\Prompts\select(
            label: 'Which tool do you want to unwire from Stalwart mail?',
            options: $options,
        );

        return [ClusterTool::from($selected)];
    }

    /**
     * The instance --domain points at, or '' for this tool's default.
     *
     * --all deliberately ignores it, the same way mail:wire does: one domain
     * cannot mean something sensible across many tools at once.
     */
    protected function targetInstance(string $kubectl, ClusterTool $tool): string
    {
        $domain = (string) ($this->option('domain') ?: '');

        if ($domain === '' || $this->option('all')) {
            return '';
        }

        return $this->resolveInstanceForDomain($kubectl, $tool, $this->sanitizeDomainInput($domain));
    }

    protected function isToolInstalledForMail(string $kubectl, ClusterTool $tool): bool
    {
        if ($tool === ClusterTool::SSO) {
            return $this->isSsoInstalled($kubectl, $this->ssoNamespace());
        }

        $engine = $tool->engines() !== [] ? $this->resolveInstanceEngine($kubectl, $tool, '', (string) ($this->option('engine') ?: '') ?: null) : null;
        $schema = $tool->smtpEnv($engine);
        if ($schema === null) {
            return false;
        }

        return trim(Process::run("{$kubectl} get deployment {$schema['deployment']} -n {$schema['namespace']} --no-headers --ignore-not-found")->output()) !== '';
    }

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
                $ssoHost = $this->resolveSsoHostReadOnly($env, null, $kubectl);
                if ($pat && $ssoHost) {
                    $this->zitadelConfigureSmtp($ssoHost, $pat, '', '', '', '', '');
                }
                $unwired[] = $tool->getLabel();

                continue;
            }

            $engine = $tool->engines() !== [] ? $this->resolveInstanceEngine($kubectl, $tool, '', (string) ($this->option('engine') ?: '') ?: null) : null;

            if ($tool->configuresViaConfigFile($engine)) {
                if ($this->unwireSynapseSmtp($kubectl, $tool)) {
                    $unwired[] = $tool->getLabel();
                }

                continue;
            }

            // Previously called with no $engine at all — for a multi-engine
            // tool (DATA) this always resolved Directus's schema regardless
            // of the engine just computed above, the same bug class as
            // isToolInstalledForMail().
            $schema = $tool->smtpEnv($engine);
            if ($schema === null) {
                continue;
            }

            $unset = array_values($schema['vars']);
            if (! empty($schema['static'])) {
                $unset = array_merge($unset, array_keys($schema['static']));
            }
            $pairs = implode(' ', array_map(fn (string $key) => $key.'-', $unset));

            $ok = false;
            $this->withSpin("Unwiring mail from {$tool->getLabel()}...", function () use ($kubectl, $schema, $pairs, &$ok): void {
                $ok = Process::run("{$kubectl} set env deployment/{$schema['deployment']} -n {$schema['namespace']} {$pairs}")->successful();
                if ($ok) {
                    Process::run("{$kubectl} rollout restart deployment/{$schema['deployment']} -n {$schema['namespace']}");
                }

                // Mirror mail:wire's also_patch loop — secondary components
                // that shared the PRIMARY's SMTP secret (e.g. GlitchTip's
                // worker) must lose it too, or they keep mailing Stalwart.
                foreach ($schema['also_patch'] ?? [] as $secondaryDeployment) {
                    if (! $ok) {
                        break;
                    }
                    $ok = Process::run("{$kubectl} set env deployment/{$secondaryDeployment} -n {$schema['namespace']} {$pairs}")->successful();
                    if ($ok) {
                        Process::run("{$kubectl} rollout restart deployment/{$secondaryDeployment} -n {$schema['namespace']}");
                    }
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

    protected function unwireSynapseSmtp(string $kubectl, ClusterTool $tool): bool
    {
        $schema = $tool->smtpEnv('matrix');
        if ($schema === null) {
            return false;
        }

        $ns = $schema['namespace'];
        $ok = true;

        $this->withSpin('Unwiring Matrix (Synapse) mail from homeserver.yaml...', function () use ($kubectl, $ns, &$ok): void {
            $oidc = $this->readChatWiredOidc($kubectl, $ns);
            $mas = $this->readChatWiredMas($kubectl, $ns);

            $raw = Process::run("{$kubectl} get secret chat-synapse-config -n {$ns} -o jsonpath='{.data.homeserver\.yaml}'")->output();
            if (trim($raw) === '') {
                $ok = false;

                return;
            }
            $rawYaml = (string) base64_decode(trim($raw));

            $homeserver = $this->renderSynapseConfig($rawYaml, null, $oidc, $mas);

            $temporaryDirectory = (new TemporaryDirectory)->permission(0700)->deleteWhenDestroyed()->create();
            $tmp = $temporaryDirectory->path().'/homeserver.yaml';
            file_put_contents($tmp, $homeserver);
            $result = Process::run(
                "{$kubectl} create secret generic chat-synapse-config -n {$ns} --from-file=homeserver.yaml={$tmp} --dry-run=client -o yaml | {$kubectl} apply -f -",
            );
            $temporaryDirectory->delete();

            $ok = $result->successful();
            if ($ok) {
                Process::run("{$kubectl} rollout restart deployment/chat-synapse -n {$ns}");
                Process::run("{$kubectl} delete secret chat-smtp -n {$ns} --ignore-not-found");
            }
        });

        if (! $ok) {
            $this->laraKubeError('Failed to unwire Synapse mail from homeserver.yaml.');
        }

        return $ok;
    }
}
