<?php

namespace App\Commands\Github;

use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithGlobalConfig;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class GhaConfigureCommand extends Command
{
    use InteractsWithEnvironments, InteractsWithGlobalConfig, LaraKubeOutput;

    protected $signature = 'gha:configure {environment? : The environment to configure (production, staging, etc.)}';

    protected $description = 'Configure GitHub Actions secrets using the native GitHub CLI container';

    public function handle(): int
    {
        $this->renderHeader();

        $environment = $this->argument('environment') ?? 'production';
        $upperEnv = strtoupper($environment);
        $projectPath = getcwd();

        $this->laraKubeInfo("Configuring GitHub Secrets for '{$environment}'...");

        // 1. Check for .env.{env} file
        $envFile = ".env.{$environment}";
        if (! file_exists($projectPath.'/'.$envFile)) {
            $this->laraKubeError("Crucial file '{$envFile}' is missing!");
            $this->line('Please create it before configuring GitHub Actions.');

            return 1;
        }

        $gh = $this->getGhCommand();

        // 2. Auto-detect repository for explicit targeting
        $repo = trim(shell_exec('git remote get-url origin 2>/dev/null') ?? '');
        $repoFlag = '';
        if ($repo) {
            // Convert git@github.com:user/repo.git or github:user/repo.git or https://github.com/user/repo to user/repo
            if (preg_match('/(?:github\.com|github)[:\/](.*?)(?:\.git)?$/', $repo, $matches)) {
                $repoName = $matches[1];
                $repoFlag = "-R {$repoName}";
                $this->info("  🛰 Targeting repository: {$repoName}");
            } else {
                $this->laraKubeWarn("Could not parse GitHub repository name from remote URL: {$repo}");
                $this->line('  The GitHub CLI may fail if it cannot detect the repository.');
            }
        } else {
            $this->laraKubeWarn("No 'origin' git remote found.");
            $this->line('  The GitHub CLI may fail if it cannot detect the repository.');
        }

        // 3. Upload ENV_FILE_BASE64
        $this->laraKubeInfo("Uploading {$upperEnv}_ENV_FILE_BASE64...");
        $envContent = file_get_contents($projectPath.'/'.$envFile);
        if (empty(trim($envContent))) {
            $this->laraKubeError("File '{$envFile}' is empty! Cannot upload an empty environment.");

            return 1;
        }
        $base64Env = base64_encode($envContent);
        $this->info('  📦 Env size: '.strlen($base64Env).' bytes (base64)');

        $this->setGithubSecret($gh, "{$upperEnv}_ENV_FILE_BASE64", $base64Env, $repoFlag);

        // 4. Upload KUBECONFIG (Surgical Extraction)
        $this->laraKubeInfo("Setting KUBECONFIG secret for {$environment}...");

        $config = $this->getProjectConfigObject($projectPath);
        $ip = $config->getCloudIp($environment);

        if (! $ip) {
            $this->laraKubeError("Could not find IP for environment '{$environment}' in cloud configuration.");
            $this->line('  Please run <fg=yellow;options=bold>larakube cloud:configure</> first.');

            return 1;
        }

        $contextName = "larakube-{$ip}";
        $this->info("  🎯 Extracting cluster context: {$contextName}");

        // Use the current environment's KUBECONFIG if set, otherwise default to home
        $localKubeConfig = getenv('KUBECONFIG') ?: $_SERVER['HOME'].'/.kube/config';

        // Use kubectl to extract a standalone, minified config for JUST this context
        // We pass the KUBECONFIG env to ensure all local files are searched
        $kubeConfigContent = shell_exec('KUBECONFIG='.escapeshellarg($localKubeConfig).' kubectl config view --context='.escapeshellarg($contextName).' --minify --flatten 2>/dev/null');

        if (empty(trim($kubeConfigContent ?? ''))) {
            $this->laraKubeError("Context '{$contextName}' not found in your local kubeconfig.");
            $this->line('  Please run <fg=yellow;options=bold>larakube cloud:provision</> first to sync your credentials.');

            return 1;
        }

        $this->info('  📦 Kubeconfig size: '.strlen($kubeConfigContent).' bytes');

        // Visual Verification (Redacted)
        if (preg_match('/server: (https:\/\/.*)/', $kubeConfigContent, $matches)) {
            $this->info("  🔗 Verified Server Target: <fg=cyan>{$matches[1]}</>");
        }

        $this->setGithubSecret($gh, "{$upperEnv}_KUBECONFIG", $kubeConfigContent, $repoFlag);

        $this->laraKubeInfo("GitHub Secrets configured successfully for '{$environment}'!");

        return 0;
    }

    protected function ensureEnvironmentExists(string $gh, string $environment, string $repoFlag): void
    {
        $repo = '';
        if (preg_match('/-R (.*)/', $repoFlag, $matches)) {
            $repo = $matches[1];
        }

        if (empty($repo)) {
            return;
        }

        $this->laraKubeInfo("Ensuring GitHub environment '{$environment}' exists...");

        $command = "{$gh} api -X PUT /repos/{$repo}/environments/{$environment} 2>&1";
        exec($command, $output, $resultCode);

        if ($resultCode !== 0) {
            $this->laraKubeWarn("Could not verify/create environment '{$environment}'.");
        }
    }

    protected function setGithubSecret(string $gh, string $name, string $value, string $repoFlag, ?string $environment = null): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'gh_secret');
        file_put_contents($tmpFile, $value);

        $envFlag = $environment ? '--env '.escapeshellarg($environment) : '';
        $command = 'cat '.escapeshellarg($tmpFile)." | {$gh} secret set ".escapeshellarg($name)." {$repoFlag} {$envFlag} 2>&1";
        exec($command, $output, $resultCode);

        @unlink($tmpFile);

        if ($resultCode !== 0) {
            $this->laraKubeError("Failed to set GitHub secret: {$name}");
            $this->line('  Command output:');
            foreach ($output as $line) {
                $this->line("  <fg=red>$line</>");
            }
            exit(1);
        }

        $this->info("  ✅ Secret '{$name}' uploaded successfully.");
    }
}
