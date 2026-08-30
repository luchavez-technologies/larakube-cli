<?php

use App\Exceptions\MissingFlagException;
use App\Traits\RequiresFlagsWhenNonInteractive;
use Illuminate\Contracts\Console\Kernel;

/**
 * The canonical shape is `larakube <tool>:<action> <environment> --flag=value`.
 *
 * These are architectural guards, not behaviour tests: they stop the next
 * command (or the next agent) from quietly reintroducing a subject positional
 * or a second environment source.
 */
test('no tool command takes a positional other than environment', function (): void {
    // Scoped to commands whose namespace IS a ClusterTool, which is what the
    // rule is about and what this test has always claimed to check. It used to
    // scan every registered command and was parked as "enable once the
    // non-tool commands are swept too" -- so it never ran anywhere, and a real
    // violation could have landed under it unnoticed.
    //
    // The commands it was tripping over are generators and utilities whose
    // positional is genuinely the subject and has no cluster meaning:
    // `vite:new {name}`, `saloon:request {integration} {name}`,
    // `snapshot:create {pvc} {name}`, `bundle:zip {path}`, `config:tld {tld}`.
    // Those are a different shape on purpose, not a backlog.
    $toolNamespaces = array_map(fn (App\Enums\ClusterTool $tool): string => $tool->value.':', App\Enums\ClusterTool::cases());

    $offenders = [];

    foreach (app(Kernel::class)->all() as $name => $command) {
        $isToolCommand = false;
        foreach ($toolNamespaces as $namespace) {
            if (str_starts_with($name, $namespace)) {
                $isToolCommand = true;
                break;
            }
        }

        if (! $isToolCommand) {
            continue;
        }

        $args = array_keys($command->getDefinition()->getArguments());

        // Zero args is fine (a global action); one arg must be `environment`.
        if ($args === [] || $args === ['environment']) {
            continue;
        }

        $offenders[$name] = $args;
    }

    expect(array_keys($offenders))->toBe([], 'Tool commands with a non-environment positional: '
        .json_encode($offenders));
});

test('the mail, sso and vpn suites take environment as their only positional', function (): void {
    foreach (app(Kernel::class)->all() as $name => $command) {
        if (! preg_match('/^(mail|sso|vpn):/', $name)) {
            continue;
        }

        $args = array_keys($command->getDefinition()->getArguments());

        expect($args)->toBe(['environment'], "{$name} should take only {environment}");
    }
});

test('no command still exposes a --env escape hatch alongside the positional', function (): void {
    // Two sources for one value is how `mail:relay --env=production` and
    // `mail:relay production` silently disagreed.
    foreach (app(Kernel::class)->all() as $name => $command) {
        if (! preg_match('/^(mail|sso|vpn|plex|tool):/', $name)) {
            continue;
        }

        expect($command->getDefinition()->hasOption('env'))
            ->toBeFalse("{$name} still defines --env");
    }
});

test('every subject a wire command needs is available as a flag', function (): void {
    // tool:add calls these with --tool. Before the inversion they only had a
    // {tool} positional, so answering "yes" to the wiring offer threw
    // InvalidOptionException — the offer was dead on arrival.
    $commands = app(Kernel::class)->all();

    foreach (['mail:wire', 'sso:wire', 'vpn:wire'] as $name) {
        expect($commands[$name]->getDefinition()->hasOption('tool'))
            ->toBeTrue("{$name} must accept --tool so tool:add can drive it");
    }
});

test('tool:add can answer its own wiring prompts from flags', function (): void {
    // Without these, tool:add could never run unattended: the mail/SSO offers
    // were unconditional confirm() calls with nothing to answer them.
    $definition = app(Kernel::class)->all()['tool:add']->getDefinition();

    foreach (['wire-mail', 'no-wire-mail', 'wire-sso', 'no-wire-sso'] as $flag) {
        expect($definition->hasOption($flag))->toBeTrue("tool:add is missing --{$flag}");
    }
});

test('cannotPrompt is true under --no-interaction so the picker guards fire', function (): void {
    // The guards in the account pickers all hang off this one predicate — if it
    // ever returns false in a non-interactive run, every guard silently becomes
    // a hang instead of an error.
    $probe = new class
    {
        use RequiresFlagsWhenNonInteractive;

        public function __construct(private array $options = ['no-interaction' => true]) {}

        public function option(string $key): mixed
        {
            return $this->options[$key] ?? null;
        }

        public function check(): bool
        {
            return $this->cannotPrompt();
        }

        public function guarded(): string
        {
            return $this->flagOrPrompt('email', fn () => 'never-reached', 'which mailbox to read');
        }
    };

    expect($probe->check())->toBeTrue()
        ->and(fn () => $probe->guarded())->toThrow(MissingFlagException::class, 'Missing required --email');
});

test('every guarded picker sits behind a cannotPrompt check', function (): void {
    // Source-level guard: a picker that calls select() without first checking
    // cannotPrompt() will hang forever in CI.
    $pickers = [
        'app/Commands/Mail/MailInboxCommand.php',
        'app/Commands/Mail/MailDeleteCommand.php',
        'app/Commands/Mail/MailPasswordCommand.php',
        'app/Commands/Mail/MailQuotaCommand.php',
        'app/Commands/Mail/MailWireCommand.php',
    ];

    foreach ($pickers as $path) {
        $source = (string) file_get_contents(base_path($path));

        // Note: toContain() treats extra arguments as additional needles, not
        // as a failure message — so the assertion carries only the one string.
        expect(str_contains($source, 'cannotPrompt()'))
            ->toBeTrue(basename($path).' has an unguarded picker prompt');
    }
});

test('MissingFlagException names a flag that the command actually defines', function (): void {
    // A guard that names a nonexistent flag is worse than no guard.
    $commands = app(Kernel::class)->all();

    $guards = [
        'mail:inbox' => 'email',
        'mail:delete' => 'email',
        'mail:password' => 'email',
        'mail:quota' => 'email',
        'mail:wire' => 'tool',
    ];

    foreach ($guards as $name => $flag) {
        expect($commands[$name]->getDefinition()->hasOption($flag))
            ->toBeTrue("{$name}'s guard points at --{$flag}, which it does not define");
    }
});

test('every cluster-inspecting :show resolves its context from {environment}', function (): void {
    // secrets:show production reported "not installed" about a healthy install
    // because it inspected the CURRENT kube-context, using {environment} only to
    // pick a host string. A :show that ignores the environment is worse than no
    // :show — it reports confident, wrong answers about the wrong cluster.
    $offenders = [];

    foreach (glob(base_path('app/Commands/*/*ShowCommand.php')) as $path) {
        $name = basename($path);

        // Abstract base + the tool:show proxy + pipeline (reads workflow files,
        // not a cluster) are legitimately exempt.
        if (str_starts_with($name, 'Abstract') || str_starts_with($name, 'Tool') || $name === 'PipelineShowCommand.php') {
            continue;
        }

        $source = (string) file_get_contents($path);

        // Either it inherits the base (which resolves), or it resolves itself.
        if (str_contains($source, 'extends AbstractToolShowCommand')
            || str_contains($source, 'resolveToolContext')
            || str_contains($source, 'environmentContextOrCurrent')) {
            continue;
        }

        $offenders[] = $name;
    }

    expect($offenders)->toBe([], 'These :show commands ignore {environment} when choosing a cluster: '
        .implode(', ', $offenders));
});
