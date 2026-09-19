<?php

namespace App\Commands\Git;

use App\Enums\ClusterTool;
use App\Services\Kubectl;
use App\Traits\DeploysClusterTool;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\password;

use LaravelZero\Framework\Commands\Command;

class GitLoginCommand extends Command
{
    use DeploysClusterTool, LaraKubeOutput;

    protected $signature = 'git:login
        {environment=local : Environment whose Forgejo to log in to}
        {--context= : Target a specific kube-context (defaults to the environment\'s saved cloud target)}
        {--domain=  : The Forgejo host, when the cluster has more than one}';

    protected $description = 'Log the tea CLI in to Forgejo, so cloud:configure can manage repository secrets';

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');
        $host = $this->resolveForgejoHost($env);

        if ($host === null) {
            return 1;
        }

        $this->laraKubeInfo("Logging in to Forgejo at {$host}.");
        $this->line("   <fg=gray>Create a token at</> <fg=blue>https://{$host}/user/settings/applications</> <fg=gray>with the</> <fg=yellow>write:repository</> <fg=gray>scope.</>");
        $this->newLine();

        $token = password(label: "Forgejo access token for {$host}", required: true);
        $tea = $this->getTeaCommand(envNames: ['GITEA_SERVER_TOKEN']);

        // Re-running replaces the login instead of failing on a duplicate name.
        Process::run(rtrim($tea).' logins delete '.escapeshellarg($host));

        $result = Process::env(['GITEA_SERVER_TOKEN' => $token])->run(
            rtrim($tea).' login add --name '.escapeshellarg($host).' --url '.escapeshellarg("https://{$host}").' --no-version-check',
        );

        if (! $result->successful()) {
            $this->laraKubeError("tea could not log in to {$host}.");
            foreach (explode("\n", trim($result->output().$result->errorOutput())) as $line) {
                $this->line("  <fg=red>{$line}</>");
            }

            return 1;
        }

        $this->laraKubeInfo("✅ tea is logged in to {$host}.");
        $this->line("   <fg=gray>Next:</> <fg=yellow>larakube cloud:configure {$env} --only=ci</> <fg=gray>from a project whose remote is on {$host}.</>");

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
            fn (array $entry) => ($entry['tool'] ?? null) === ClusterTool::GIT->value ? ($entry['host'] ?? null) : null,
            $this->getRegisteredTools($kubectl),
        ))));

        if ($hosts === []) {
            $this->laraKubeError("No Forgejo is registered on '{$env}'.");
            $this->line("   <fg=gray>Run</> <fg=yellow>larakube git:init {$env}</> <fg=gray>first, or pass</> <fg=yellow>--domain=</><fg=gray>.</>");

            return null;
        }

        if (count($hosts) > 1) {
            $this->laraKubeError("'{$env}' has more than one Forgejo host: ".implode(', ', $hosts).'.');
            $this->line('   <fg=gray>Pick one with</> <fg=yellow>--domain=</><fg=gray>.</>');

            return null;
        }

        return $hosts[0];
    }
}
