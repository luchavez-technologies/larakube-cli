<?php

namespace App\Commands\Cloud;

use App\Data\ConfigData;
use App\Traits\ProvisionsDevBox;

/**
 * Creates a server used as a development machine: the same server creation and hardening as
 * cloud:create, then the stack `larakube setup` builds on a developer's own computer, run on the
 * box. No deployment k3s is installed, so a project made there uses the normal new, up and deploy
 * flow. A server holds one k3s, which is why this is its own command rather than a cloud:create flag.
 */
class DevboxCreateCommand extends CloudCreateCommand
{
    use ProvisionsDevBox;

    protected $signature = 'devbox:create
        {--provider= : Cloud provider slug (do, hetzner, gcp, aws). Default or prompted.}
        {--stack-name= : Name of the dev box (skips the prompt; slugified)}
        {--region= : Provider region slug (e.g. nyc1, fsn1, us-central1, us-east-1)}
        {--zone= : GCP Compute zone (defaults to <region>-a)}
        {--size= : Server size slug (8 GB of RAM is comfortable for one app)}
        {--key= : Path to the SSH private key}
        {--admin-cidr= : Restrict SSH to this CIDR; omit = open}
        {--channel= : LaraKube CLI channel installed on the box: canary (default) or stable}
        {--do-token= : DigitalOcean API token for this run only (never persisted)}
        {--do-account= : DigitalOcean account ID or name to use from global config}
        {--hetzner-token= : Hetzner Cloud API token for this run only}
        {--hetzner-account= : Hetzner account ID or name to use from global config}
        {--gcp-project= : Google Cloud Project ID for this run only}
        {--gcp-account= : Google Cloud account email for this run only}
        {--gcp-credentials= : Path to Google Cloud Service Account JSON key for this run only}
        {--aws-profile= : AWS CLI profile name for this run only}
        {--aws-region= : AWS region for this run only}
        {--aws-access-key-id= : AWS Access Key ID for this run only}
        {--aws-secret-access-key= : AWS Secret Access Key for this run only}
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = 'Create a server as a development machine: Podman, a local cluster and the LaraKube CLI';

    protected $aliases = [];

    protected function stackRole(): string
    {
        return 'dev';
    }

    protected function mayAttachToExisting(): bool
    {
        return false;
    }

    protected function needsKubectl(): bool
    {
        return false;
    }

    protected function resolveTargetKind(?string $provider = null): ?string
    {
        return 'vps';
    }

    protected function provisionHost(string $stackName, string $ip, string $keyPath, ?ConfigData $config, ?string $adminCidr): ?string
    {
        $channel = in_array($this->flag('channel'), ['canary', 'stable'], true) ? (string) $this->flag('channel') : 'canary';

        return $this->provisionDevBox($stackName, $ip, $keyPath, $channel, $adminCidr) === null ? null : '';
    }

    protected function finishVps(string $stackName, string $ip, string $keyPath, string $context, ?ConfigData $config, ?string $projectPath, ?string $environment): int
    {
        $this->result += ['ip' => $ip, 'context' => null, 'role' => 'dev'];

        $this->newLine();
        $this->laraKubeInfo('✅ Dev box ready!');
        $this->line("  Connect:      <fg=cyan>ssh {$stackName}</>");
        $this->line('  Make an app:  <fg=cyan>larakube new my-app</> on the box, then <fg=cyan>larakube up</>');
        $this->line("  See an app:   <fg=cyan>ssh -L 8443:127.0.0.1:443 {$stackName}</> and open it on port 8443");
        $this->line("  Remove it:    <fg=cyan>larakube cloud:destroy {$stackName}</>");

        return 0;
    }
}
