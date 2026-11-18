<?php

namespace App\Commands\Git;

use App\Enums\CliTool;
use App\Enums\ClusterTool;
use App\Services\Kubectl;
use App\Traits\DeploysClusterTool;
use App\Traits\EmitsJsonOutput;
use App\Traits\FailsWithJson;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsCommandOptions;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\password;

use LaravelZero\Framework\Commands\Command;

class GitLoginCommand extends Command
{
    use DeploysClusterTool, EmitsJsonOutput, FailsWithJson, LaraKubeOutput, ReadsCommandOptions;

    protected $signature = 'git:login
        {environment=local : Environment whose Forgejo to log in to}
        {--context= : Target a specific kube-context (defaults to the environment\'s saved cloud target)}
        {--domain=  : The Forgejo host, when the cluster has more than one}
        {--name=    : Custom tea login name (defaults to the Forgejo host)}
        {--token=   : Forgejo personal access token (non-interactive)}
        {--json     : Emit machine-readable JSON output}';

    protected $description = 'Log the tea CLI in to Forgejo, so cloud:configure can manage repository secrets';

    public function handle(): int
    {
        if ($this->flag('json')) {
            $this->enableJsonMode();
        }

        $this->renderHeader();

        if (! CliTool::TEA->isAvailable()) {
            $this->line('  Install it by running: <fg=yellow>larakube setup --tools=tea</>');

            return $this->failed('Tea CLI (tea) is not installed.');
        }

        $env = (string) $this->argument('environment');
        $host = $this->resolveForgejoHost($env);

        if ($host === null) {
            return 1;
        }

        $token = trim((string) ($this->flag('token') ?: getenv('GITEA_SERVER_TOKEN') ?: ''));
        if ($token === '') {
            if ($this->flag('json') || $this->option('no-interaction')) {
                return $this->failed('Forgejo access token is required (--token or GITEA_SERVER_TOKEN).');
            }

            $this->laraKubeInfo("Logging in to Forgejo at {$host}.");
            $this->line("   <fg=gray>Create a token at</> <fg=blue>https://{$host}/user/settings/applications</> <fg=gray>with the</> <fg=yellow>read:user</> <fg=gray>and</> <fg=yellow>write:repository</> <fg=gray>scopes.</>");
            $this->newLine();

            $token = password(
                label: "Forgejo access token for {$host}",
                placeholder: 'Generate one at https://'.$host.'/user/settings/applications',
                required: true,
            );
        } else {
            $this->laraKubeInfo("Logging in to Forgejo at {$host}.");
        }

        $this->registerSecret($token);

        $tea = $this->getTeaCommand(envNames: ['GITEA_SERVER_TOKEN']);
        $loginName = trim((string) $this->flag('name')) ?: $host;

        // Re-running replaces the login instead of failing on a duplicate name.
        Process::run(rtrim($tea).' logins delete '.escapeshellarg($loginName));

        $result = Process::env(['GITEA_SERVER_TOKEN' => $token])->run(
            rtrim($tea).' login add --name '.escapeshellarg($loginName).' --url '.escapeshellarg("https://{$host}").' --no-version-check',
        );

        if (! $result->successful()) {
            $errorDetail = trim($result->errorOutput() ?: $result->output());
            foreach (explode("\n", trim($result->output().$result->errorOutput())) as $line) {
                if ($line !== '') {
                    $this->line("  <fg=red>{$line}</>");
                }
            }

            return $this->failed($errorDetail !== '' ? "tea could not log in to {$host}: {$errorDetail}" : "tea could not log in to {$host}.");
        }

        $this->laraKubeInfo("✅ tea is logged in to {$host}.");
        $this->line("   <fg=gray>Next:</> <fg=yellow>larakube cloud:configure {$env} --only=ci</> <fg=gray>from a project whose remote is on {$host}.</>");

        if ($this->flag('json')) {
            $this->jsonOutput(['success' => true, 'host' => $host, 'environment' => $env]);
        }

        return 0;
    }

    /** The Forgejo host recorded for this environment's cluster. */
    protected function resolveForgejoHost(string $env): ?string
    {
        $domain = trim((string) $this->option('domain'));
        if ($domain !== '') {
            return $domain;
        }

        $context = $this->resolveToolContext($env, (string) $this->option('context') ?: null);
        $kubectl = Kubectl::forContext($context)->prefix();

        $hosts = array_values(array_unique(array_filter(array_map(
            fn (array $entry) => ($entry['tool'] ?? null) === ClusterTool::FORGEJO->value ? ($entry['host'] ?? null) : null,
            $this->getRegisteredTools($kubectl),
        ))));

        if ($hosts === []) {
            $this->line("   <fg=gray>Run</> <fg=yellow>larakube tool:init --tool=forgejo {$env}</> <fg=gray>first, or pass</> <fg=yellow>--domain=</><fg=gray>.</>");
            $this->failed("No Forgejo is registered on '{$env}'.");

            return null;
        }

        if (count($hosts) > 1) {
            $this->line('   <fg=gray>Pick one with</> <fg=yellow>--domain=</><fg=gray>.</>');
            $this->failed("'{$env}' has more than one Forgejo host: ".implode(', ', $hosts).'.');

            return null;
        }

        return $hosts[0];
    }
}
