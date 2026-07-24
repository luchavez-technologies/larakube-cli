<?php

use Illuminate\Contracts\Console\Kernel;

/**
 * The canonical shape is `larakube <tool>:<action> <environment> --flag=value`.
 *
 * These are architectural guards, not behaviour tests: they stop the next
 * command (or the next agent) from quietly reintroducing a subject positional
 * or a second environment source.
 */

/** Commands that are genuinely not tool-scoped and so are out of scope here. */
function shapeExemptPrefixes(): array
{
    return ['make:', 'app:', 'stub:', 'completion', 'help', 'list', 'test', 'schedule'];
}

test('no tool command takes a positional other than environment', function () {
    $offenders = [];

    foreach (app(Kernel::class)->all() as $name => $command) {
        if (! str_contains($name, ':')) {
            continue;
        }
        foreach (shapeExemptPrefixes() as $prefix) {
            if (str_starts_with($name, $prefix)) {
                continue 2;
            }
        }

        $args = array_keys($command->getDefinition()->getArguments());

        // Zero args is fine (a global action); one arg must be `environment`.
        if ($args === [] || $args === ['environment']) {
            continue;
        }

        $offenders[$name] = $args;
    }

    // Known, deliberate exceptions — each takes a genuinely non-environment
    // subject that has no cluster meaning (a file path, a passthrough command
    // line, a service name).
    $allowed = [
        'plex:remove', 'tool:add', 'tool:remove', 'ext:add', 'ext:remove',
        'context:remove', 'context:import', 'companion:remove',
    ];

    foreach ($allowed as $name) {
        unset($offenders[$name]);
    }

    expect(array_keys($offenders))->toBe([], 'Commands with a non-environment positional: '
        .json_encode($offenders));
})->skip('Inventory guard — enable once the non-tool commands are swept too.');

test('the mail, sso and vpn suites take environment as their only positional', function () {
    foreach (app(Kernel::class)->all() as $name => $command) {
        if (! preg_match('/^(mail|sso|vpn):/', $name)) {
            continue;
        }

        $args = array_keys($command->getDefinition()->getArguments());

        expect($args)->toBe(['environment'], "{$name} should take only {environment}");
    }
});

test('no command still exposes a --env escape hatch alongside the positional', function () {
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

test('every subject a wire command needs is available as a flag', function () {
    // tool:add calls these with --tool. Before the inversion they only had a
    // {tool} positional, so answering "yes" to the wiring offer threw
    // InvalidOptionException — the offer was dead on arrival.
    $commands = app(Kernel::class)->all();

    foreach (['mail:wire', 'sso:wire', 'vpn:wire'] as $name) {
        expect($commands[$name]->getDefinition()->hasOption('tool'))
            ->toBeTrue("{$name} must accept --tool so tool:add can drive it");
    }
});

test('tool:add can answer its own wiring prompts from flags', function () {
    // Without these, tool:add could never run unattended: the mail/SSO offers
    // were unconditional confirm() calls with nothing to answer them.
    $definition = app(Kernel::class)->all()['tool:add']->getDefinition();

    foreach (['wire-mail', 'no-wire-mail', 'wire-sso', 'no-wire-sso'] as $flag) {
        expect($definition->hasOption($flag))->toBeTrue("tool:add is missing --{$flag}");
    }
});

test('cannotPrompt is true under --no-interaction so the picker guards fire', function () {
    // The guards in the account pickers all hang off this one predicate — if it
    // ever returns false in a non-interactive run, every guard silently becomes
    // a hang instead of an error.
    $probe = new class
    {
        use App\Traits\RequiresFlagsWhenNonInteractive;

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

    expect($probe->check())->toBeTrue();
    expect(fn () => $probe->guarded())
        ->toThrow(App\Exceptions\MissingFlagException::class, 'Missing required --email');
});

test('every guarded picker sits behind a cannotPrompt check', function () {
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

test('MissingFlagException names a flag that the command actually defines', function () {
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

test('every cluster-inspecting :show resolves its context from {environment}', function () {
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
