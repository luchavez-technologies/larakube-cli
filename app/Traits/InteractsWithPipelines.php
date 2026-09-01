<?php

namespace App\Traits;

use Exception;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;

trait InteractsWithPipelines
{
    /** Discover all generated workflows in the current workspace. */
    protected function discoverWorkflows(string $dir): array
    {
        $workflows = [];
        $dirReal = realpath($dir) ?: $dir;

        // GitHub Actions
        $ghDir = $dirReal.'/.github/workflows';
        if (is_dir($ghDir)) {
            foreach (glob("{$ghDir}/larakube-deploy-*.yml") as $file) {
                $fileReal = realpath($file) ?: $file;
                $workflows[] = [
                    'platform' => 'github',
                    'file' => str_replace($dirReal.'/', '', $fileReal),
                    'env' => $this->parseWorkflowEnv(basename($fileReal)) ?? 'unknown',
                ];
            }
        }

        // Forgejo Actions
        $gtDir = $dirReal.'/.forgejo/workflows';
        if (is_dir($gtDir)) {
            foreach (glob("{$gtDir}/larakube-deploy-*.yml") as $file) {
                $fileReal = realpath($file) ?: $file;
                $workflows[] = [
                    'platform' => 'forgejo',
                    'file' => str_replace($dirReal.'/', '', $fileReal),
                    'env' => $this->parseWorkflowEnv(basename($fileReal)) ?? 'unknown',
                ];
            }
        }

        // GitLab CI/CD
        $glFile = $dirReal.'/.gitlab-ci.yml';
        if (file_exists($glFile)) {
            $workflows[] = [
                'platform' => 'gitlab',
                'file' => '.gitlab-ci.yml',
                'env' => 'all',
            ];
        }

        return $workflows;
    }

    /** Extract environment name from workflow filename. */
    protected function parseWorkflowEnv(string $filename): ?string
    {
        if (preg_match('/larakube-deploy-([a-zA-Z0-9_\-]+)\.yml$/', $filename, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /** Resolve the triggers (e.g. push to main branch) for a workflow file. */
    protected function parseWorkflowTrigger(string $path): string
    {
        if (! file_exists($path)) {
            return 'unknown';
        }

        if (basename($path) === '.gitlab-ci.yml') {
            return 'push';
        }

        try {
            $yaml = Yaml::parseFile($path);
            if (! is_array($yaml)) {
                return 'unknown';
            }

            $on = $yaml['on'] ?? null;
            if (is_string($on)) {
                return $on;
            }

            if (is_array($on)) {
                $push = $on['push'] ?? null;
                if (is_array($push) && isset($push['branches'])) {
                    $branches = (array) $push['branches'];

                    return 'push ('.implode(', ', $branches).')';
                }

                return implode(', ', array_keys($on));
            }
        } catch (Exception $e) {
            // Silence YAML errors
        }

        return 'unknown';
    }

    /** Find all secret variable names reference in a parsed YAML content. */
    protected function extractSecretsFromYaml(array $yaml): array
    {
        $secrets = [];
        $raw = json_encode($yaml);

        // Match ${{ secrets.VAR_NAME }} or similar GHA syntax
        if (preg_match_all('/secrets\.([A-Z0-9_]+)/', $raw, $matches)) {
            $secrets = array_merge($secrets, $matches[1]);
        }

        // Match $VAR_NAME style GitLab secrets (e.g., KUBECONFIG, ENV_FILE_BASE64)
        if (preg_match_all('/\$([A-Z0-9_]+)/', $raw, $matches)) {
            foreach ($matches[1] as $var) {
                if (str_contains($var, 'KUBECONFIG') || str_contains($var, 'ENV_FILE_BASE64')) {
                    $secrets[] = $var;
                }
            }
        }

        return array_values(array_unique($secrets));
    }

    /** Get the absolute path to the local act binary, or null. */
    protected function getActPath(): ?string
    {
        $out = trim(Process::run('which act')->output());

        return $out !== '' ? $out : null;
    }
}
