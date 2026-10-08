<?php

namespace App\Commands\Tool;

use App\Enums\ClusterTool;
use App\Services\Tools\InitOption;
use App\Services\Tools\ToolInitOptions;
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

    /** What tool:add already answers itself, so tool-specific options never shadow it. */
    private const OWN_OPTIONS = ['tool', 'context', 'domain', 'force', 'wire-mail', 'no-wire-mail', 'wire-sso', 'no-wire-sso', 'confirm-commons-restart'];

    protected $signature = 'tool:add
        {environment? : The environment to target}
        {--tool= : Comma-separated tool slugs to install (e.g. flow,passwords)}
        {--context= : Target a specific kube-context}
        {--domain=  : Base domain for all tool hosts (e.g. example.com → flow.example.com)}
        {--wire-mail : Wire each installed tool to Stalwart without asking}
        {--no-wire-mail : Never wire to Stalwart, even interactively}
        {--wire-sso : Wire each installed tool to Zitadel SSO without asking}
        {--no-wire-sso : Never wire to SSO, even interactively}
        {--confirm-commons-restart : Confirm restarting a running Commons service (e.g. Redis), without an interactive prompt}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Interactively discover and install LaraKube shared cluster tools';

    public function __construct()
    {
        parent::__construct();

        // Any tool's own init option can be given here (`--app-name=`, `--no-plex`)
        // and is passed to each tool that takes it.
        foreach (ToolInitOptions::union() as $option) {
            if (! $this->getDefinition()->hasOption($option->name)) {
                $this->getDefinition()->addOption(ToolInitOptions::inputOption($option));
            }
        }
    }

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
        if ($this->option('confirm-commons-restart')) {
            $params['--confirm-commons-restart'] = true;
        }

        $extra = array_values(array_filter(
            ToolInitOptions::union(),
            fn (InitOption $option): bool => ! in_array($option->name, self::OWN_OPTIONS, true),
        ));
        $given = ToolInitOptions::given($this->input, array_map(fn (InitOption $option): string => $option->name, $extra));

        // Refuse before anything is installed, so a tool is never left half
        // done because a later one does not take an option.
        foreach ($tools as $tool) {
            if (($refused = ToolInitOptions::refusedBy($tool, $given)) !== []) {
                $this->laraKubeError("{$tool->brandName()} has no --{$refused[0]} option.");

                return 1;
            }
        }

        foreach ($extra as $option) {
            if (in_array($option->name, $given, true)) {
                $params['--'.$option->name] = ToolInitOptions::forwardedValue($this->input, $option);
            }
        }

        $exitCode = 0;

        foreach ($tools as $tool) {
            $this->line("Deploying {$tool->initInvocation()}...");
            $this->newLine();

            $result = $this->call('tool:init', ['--tool' => $tool->canonicalTool()->value] + $params);

            if ($result === 0) {
                // {tool}:init already registered itself WITH its resolved host and instance.
                // Re-registering here is only to catch a single-instance tool whose own
                // init forgot to register itself. Multi-instance tools MUST NOT be registered
                // without an instance or host.
                if (! $this->isToolRegistered($kubectl, $tool) && ! $tool->supportsMultipleInstances()) {
                    $this->registerTool($kubectl, $tool);
                }
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
