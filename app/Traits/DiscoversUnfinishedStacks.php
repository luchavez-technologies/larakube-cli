<?php

namespace App\Traits;

use App\Data\StackData;

/**
 * Finds OpenTofu workdirs under ~/.larakube/tofu/ that no registered stack owns —
 * what an interrupted `cloud:create` leaves behind. Shared by `cloud:destroy`
 * (so they can be torn down) and `cloud:stacks` (so they are visible at all).
 */
trait DiscoversUnfinishedStacks
{
    use InteractsWithGlobalConfig;

    /**
     * Scan ~/.larakube/tofu/ for unregistered stack directories that contain
     * unfinished terraform state or config (e.g. from an interrupted cloud:create).
     *
     * @return array<string, StackData>
     */
    protected function getUnfinishedStacks(): array
    {
        $baseDir = home_path('.larakube/tofu');
        if (! is_dir($baseDir)) {
            return [];
        }

        $registered = array_keys($this->getGlobalConfig()->getStacks());
        $dirs = glob($baseDir.'/*', GLOB_ONLYDIR);
        if ($dirs === false) {
            return [];
        }

        $unfinished = [];
        foreach ($dirs as $dir) {
            $name = basename($dir);
            if (in_array($name, $registered, true)) {
                continue;
            }

            if (file_exists($dir.'/terraform.tfstate') || file_exists($dir.'/main.tf')) {
                $unfinished[$name] = $this->synthesizeUnfinishedStack($name, $dir);
            }
        }

        return $unfinished;
    }

    /**
     * Synthesize a StackData instance from an unregistered tofu workdir.
     */
    protected function synthesizeUnfinishedStack(string $name, string $dir): StackData
    {
        $provider = 'do';
        $kind = 'vps';
        $region = null;
        $ip = null;
        $account = null;
        $projectId = null;

        $mainTf = file_exists($dir.'/main.tf') ? (string) file_get_contents($dir.'/main.tf') : '';

        if (str_contains($mainTf, 'provider "google"') || str_contains($mainTf, 'hashicorp/google')) {
            $provider = 'gcp';
        } elseif (str_contains($mainTf, 'provider "aws"') || str_contains($mainTf, 'hashicorp/aws')) {
            $provider = 'aws';
        } elseif (str_contains($mainTf, 'provider "hcloud"') || str_contains($mainTf, 'hetznercloud/hcloud')) {
            $provider = 'hetzner';
        } elseif (str_contains($mainTf, 'provider "digitalocean"') || str_contains($mainTf, 'digitalocean/digitalocean')) {
            $provider = 'do';
        }

        if (str_contains($mainTf, 'doks') || str_contains($mainTf, 'kubernetes_cluster') || str_contains($mainTf, 'google_container_cluster') || str_contains($mainTf, 'aws_eks_cluster')) {
            $kind = 'cluster';
        }

        if (preg_match('/region\s*=\s*"([^"]+)"/', $mainTf, $m)) {
            $region = $m[1];
        }

        $stateFile = $dir.'/terraform.tfstate';
        if (file_exists($stateFile)) {
            $content = (string) file_get_contents($stateFile);
            $json = json_decode($content, true);
            if (is_array($json)) {
                if (! empty($json['outputs']['ip']['value'])) {
                    $ip = (string) $json['outputs']['ip']['value'];
                }

                // Check for project in resources
                if (! empty($json['resources']) && is_array($json['resources'])) {
                    foreach ($json['resources'] as $res) {
                        if (! empty($res['instances'][0]['attributes']['project'])) {
                            $projectId = (string) $res['instances'][0]['attributes']['project'];
                            break;
                        }
                    }
                }
            }
        }

        // Fallbacks for GCP / AWS account / project if not inferred from state
        if ($provider === 'gcp') {
            $global = $this->getGlobalConfig();
            $projectId = $projectId ?: $global->getGcpProjectId();
            $account = $global->getGcpAccount();
        } elseif ($provider === 'aws') {
            $global = $this->getGlobalConfig();
            $account = $global->getAwsProfile();
            $region = $region ?: $global->getAwsRegion();
        }

        return new StackData(
            name: $name,
            provider: $provider,
            kind: $kind,
            region: $region,
            context: $ip ? "larakube-{$ip}" : null,
            ip: $ip,
            bindings: [],
            account: $account,
            projectId: $projectId,
            createdAt: null,
        );
    }
}
