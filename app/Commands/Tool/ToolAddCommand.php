<?php

namespace App\Commands\Tool;

use App\Enums\ClusterTool;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\InteractsWithMail;
use App\Traits\InteractsWithSso;
use App\Traits\LaraKubeOutput;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\ResolvesClusterTool;
use App\Traits\ResolvesStandaloneEnvironment;

use function Laravel\Prompts\confirm;

use LaravelZero\Framework\Commands\Command;

class ToolAddCommand extends Command
{
    use ConfirmsDestructiveAction, InteractsWithMail, InteractsWithSso, LaraKubeOutput, RequiresFlagsWhenNonInteractive, ResolvesClusterTool, ResolvesStandaloneEnvironment;

    protected $signature = 'tool:add
        {environment? : The environment to target}
        {--tool= : Comma-separated tool slugs to install (e.g. flow,passwords)}
        {--context= : Target a specific kube-context}
        {--domain=  : Base domain for all tool hosts (e.g. example.com → flow.example.com)}
        {--wire-mail : Wire each installed tool to Stalwart without asking}
        {--no-wire-mail : Never wire to Stalwart, even interactively}
        {--wire-sso : Wire each installed tool to Zitadel SSO without asking}
        {--no-wire-sso : Never wire to SSO, even interactively}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Interactively discover and install LaraKube shared cluster tools';

    public function handle(): int
    {
        $this->renderHeader();

        [$env, $kubectl] = $this->resolveStandaloneEnvironmentAndKubectl();

        $tools = $this->resolveTools($kubectl, 'install');

        if (empty($tools)) {
            return 0;
        }

        if (! $this->confirmDestructive($this->installLines($tools))) {
            return 0;
        }

        $params = ['--no-interaction' => true, '--force' => true];
        if ($env) {
            $params['environment'] = $env;
        }
        if ($this->option('context')) {
            $params['--context'] = $this->option('context');
        }
        $domain = $this->option('domain');
        if ($domain) {
            $params['--domain'] = $domain;
        }

        $exitCode = 0;

        foreach ($tools as $tool) {
            $this->line("Proxying to {$tool->value}:init...");
            $this->newLine();

            $result = $this->call("{$tool->value}:init", $params);

            if ($result === 0) {
                // {tool}:init already registered itself WITH its resolved host.
                // Re-registering here is only to catch a tool whose own init
                // forgot to — registerTool() merges, so it can no longer wipe
                // the host that was just recorded (it used to).
                $this->registerTool($kubectl, $tool);
                $this->offerMailWiring($kubectl, $tool);
                $this->offerSsoWiring($kubectl, $tool);
            } else {
                $exitCode = $result;
            }
        }

        return $exitCode;
    }

    /**
     * If the freshly-installed tool sends email and Stalwart is present, offer
     * to wire it up right away. Any tool that declares smtpEnv() gets this for
     * free — no per-tool code here.
     */
    protected function offerMailWiring(string $kubectl, ClusterTool $tool): void
    {
        if ($tool->smtpEnv() === null) {
            return;
        }

        // Check if mail (Stalwart) is actually installed in the cluster registry
        if (! $this->isToolRegistered($kubectl, ClusterTool::MAIL)) {
            return;
        }

        $this->newLine();

        // Previously an unconditional confirm(), which made tool:add impossible
        // to drive from CI — and the call below passed --tool to a command that
        // only had a {tool} positional, so answering "yes" threw
        // InvalidOptionException. Both are fixed: the question has backing flags,
        // and mail:wire now really does take --tool.
        $wire = $this->flagOrConfirm(
            'wire-mail',
            fn () => confirm("Wire {$tool->getLabel()} to your Stalwart mail server now?", true),
        );

        if ($wire) {
            $this->call('mail:wire', ['--tool' => $tool->value] + $this->wireParams());
        }
    }

    /**
     * Environment + context forwarded to a wire command, so wiring lands on the
     * same cluster the tool was just installed on rather than the default local.
     *
     * @return array<string, string>
     */
    protected function wireParams(): array
    {
        $params = [];

        $env = (string) ($this->argument('environment') ?: '');
        if ($env !== '') {
            $params['environment'] = $env;
        }

        $context = (string) ($this->option('context') ?? '');
        if ($context !== '') {
            $params['--context'] = $context;
        }

        return $params;
    }

    /**
     * If the freshly-installed tool supports OIDC login and Zitadel is
     * present, offer to wire it up right away. Any tool that declares
     * oidcEnv() gets this for free — no per-tool code here.
     */
    protected function offerSsoWiring(string $kubectl, ClusterTool $tool): void
    {
        if ($tool->oidcEnv() === null) {
            return;
        }

        if (! $this->isToolRegistered($kubectl, ClusterTool::SSO)) {
            return;
        }

        $this->newLine();

        $wire = $this->flagOrConfirm(
            'wire-sso',
            fn () => confirm("Wire {$tool->getLabel()} to your Zitadel SSO now?", true),
        );

        if ($wire) {
            $this->call('sso:wire', ['--tool' => $tool->value] + $this->wireParams());
        }
    }
}
