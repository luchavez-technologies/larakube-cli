<?php

namespace App\Commands\Pipeline;

use App\Traits\ConfiguresCloudEnvironment;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithGitForge;
use App\Traits\InteractsWithGlobalConfig;
use App\Traits\InteractsWithPipelines;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\InteractsWithScopedRbac;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesEnvironmentContext;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;
use Spatie\TemporaryDirectory\TemporaryDirectory;

class PipelineTestCommand extends Command
{
    use ConfiguresCloudEnvironment,
        GeneratesProjectInfrastructure,
        InteractsWithClusterContext,
        InteractsWithEnvironments,
        InteractsWithGitForge,
        InteractsWithGlobalConfig,
        InteractsWithPipelines,
        InteractsWithProjectConfig,
        InteractsWithScopedRbac,
        LaraKubeOutput,
        ResolvesEnvironmentContext,
        StreamsProcessOutput;

    protected $signature = 'pipeline:test
        {environment? : Target environment of the workflow to run}
        {--job= : Specify a single job to run (defaults to build)}
        {--dry-run : Run act in dry-run mode}
        {--force : Run the deploy job locally without confirmation}';

    protected $description = 'Execute a workflow locally using act';

    public function handle(): int
    {
        $this->renderHeader();

        // 1. Prerequisites check
        $actPath = $this->getActPath();
        if (! $actPath) {
            $this->laraKubeError("The 'act' CLI tool is required to run workflows locally.");
            $this->line('  Install it via Homebrew: <fg=green>brew install nektos/tap/act</>');

            return 1;
        }

        $dockerCheck = Process::run('docker info');
        if (! $dockerCheck->successful()) {
            $this->laraKubeError('Docker daemon is not running. Please start Docker and try again.');

            return 1;
        }

        // 2. Discover workflows
        $workflows = $this->discoverWorkflows(getcwd());
        if (empty($workflows)) {
            $this->warn('  No LaraKube workflows/pipelines found in this project.');

            return 1;
        }

        $environment = $this->argument('environment');
        if (! $environment) {
            $envs = array_unique(array_column($workflows, 'env'));
            $environment = select(
                label: 'Which environment workflow do you want to test?',
                options: array_combine($envs, $envs),
            );
        }

        $matches = array_filter($workflows, fn ($w) => $w['env'] === $environment);
        if (empty($matches)) {
            $this->laraKubeError("No workflow found matching environment '{$environment}'.");

            return 1;
        }

        $selected = reset($matches);
        if (count($matches) > 1) {
            $options = [];
            foreach ($matches as $idx => $m) {
                $options[$idx] = "{$m['platform']} ({$m['file']})";
            }
            $choice = select(
                label: 'Multiple workflows found. Select one to run:',
                options: $options,
            );
            $selected = $matches[$choice];
        }

        if ($selected['platform'] === 'gitlab') {
            $this->laraKubeError("Local testing via 'act' is not supported for GitLab CI/CD pipelines (.gitlab-ci.yml).");

            return 1;
        }

        // 3. Resolve jobs and safety checks
        $job = $this->option('job');
        if ($job === 'deploy' && ! $this->option('force')) {
            $this->laraKubeWarn('⚠️ WARNING: Running the \'deploy\' job locally will execute commands against your target cluster.');
            if (! confirm('Are you sure you want to run the deploy job locally?', false)) {
                $this->laraKubeInfo('Action cancelled.');

                return 0;
            }
        }

        if (! $job) {
            $job = 'build';
            $this->info("  ℹ️ No --job specified — running only the safe 'build' job to prevent accidental deploy.");
        }

        // 4. Construct mock secrets. No .env content is a workflow secret
        // anymore — public/build vars are baked as literal `echo` lines in
        // the generated workflow itself, so the only secret `act` needs to
        // resolve is KUBECONFIG.
        $config = $this->getProjectConfigObject(getcwd());
        $adminContext = $this->environmentContextOrCurrent($config, $environment);
        $namespace = $config->getName().'-'.$environment;

        $kubeconfig = '';
        if ($adminContext && $this->environmentContextReachable($adminContext)) {
            $kubeconfig = $this->mintScopedKubeconfig($adminContext, $namespace) ?? '';
        }

        if ($kubeconfig === '') {
            $kubeconfig = "apiVersion: v1\nkind: Config\nclusters: []\ncontexts: []\ncurrent-context: \"\"\nusers: []\n";
        }

        $upperEnv = strtoupper($environment);
        $secretsContent = implode("\n", [
            "{$upperEnv}_KUBECONFIG={$kubeconfig}",
            "KUBECONFIG={$kubeconfig}",
        ]);

        $temporaryDirectory = (new TemporaryDirectory)->permission(0700)->deleteWhenDestroyed()->create();
        $secretsFile = $temporaryDirectory->path().'/act-secrets';
        file_put_contents($secretsFile, $secretsContent);

        // 5. Build and execute act command
        $actCmd = escapeshellarg($actPath).' -W '.escapeshellarg($selected['file']).' --secret-file '.escapeshellarg($secretsFile);
        if ($job) {
            $actCmd .= ' -j '.escapeshellarg($job);
        }
        if ($this->option('dry-run')) {
            $actCmd .= ' --dryrun';
        }

        $this->laraKubeInfo("Executing local 'act' runner for job '{$job}'...");
        $exitCode = $this->runStreaming($actCmd);

        $temporaryDirectory->delete();

        if ($exitCode === 0) {
            $this->laraKubeInfo('✅ Local act workflow completed successfully.');
        } else {
            $this->laraKubeError('❌ Local act workflow failed.');
        }

        return $exitCode;
    }
}
