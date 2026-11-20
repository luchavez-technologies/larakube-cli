<?php

namespace App\Commands\Supabase;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Services\Kubectl;

class SupabaseShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::SUPABASE;
    }

    protected function afterTable(?string $host, string $env, string $instance = ''): void
    {
        $creds = $this->credentials($host, $env, $instance);

        if (isset($creds['admin_username'], $creds['admin_password'])) {
            $this->newLine();
            $this->line('  <fg=gray>Studio login (basic auth):</>');
            $this->line("  <fg=gray>Username:</>  <fg=blue>{$creds['admin_username']}</>");
            $this->line("  <fg=gray>Password:</>  <fg=yellow>{$creds['admin_password']}</>");
            $this->newLine();
        }

        if (isset($creds['publishable_key'], $creds['secret_key'])) {
            $this->line('  <fg=gray>API keys (send as the apikey header):</>');
            $this->line("  <fg=gray>Publishable:</> <fg=blue>{$creds['publishable_key']}</> <fg=gray>(safe in browsers and apps)</>");
            $this->line("  <fg=gray>Secret:</>      <fg=yellow>{$creds['secret_key']}</> <fg=gray>(servers only: bypasses row-level security)</>");
            $this->newLine();
        }

        if (isset($creds['s3_endpoint'])) {
            $this->line('  <fg=gray>S3 endpoint (rclone, AWS SDKs; path-style):</>');
            $this->line("  <fg=gray>Endpoint:</>    <fg=blue>{$creds['s3_endpoint']}</>");
            $this->line("  <fg=gray>Region:</>      <fg=blue>{$creds['s3_region']}</>");
            $this->line("  <fg=gray>Access key:</>  <fg=blue>{$creds['s3_access_key_id']}</>");
            $this->line("  <fg=gray>Secret key:</>  <fg=yellow>{$creds['s3_secret_access_key']}</>");
            $this->line('  <fg=gray>These keys can read and write every bucket.</>');
            $this->newLine();
        }
    }

    /**
     * Studio sits behind the gateway's basic auth; its login lives in the instance's Secret
     * (also in OpenBao as SUPABASE_DASHBOARD_PASSWORD). The sb_ API keys and Storage's S3 key pair come from the same Secret.
     *
     * @return array<string, string>|null
     */
    protected function credentials(?string $host, string $env, string $instance = ''): ?array
    {
        if ($instance === '') {
            return null;
        }

        $context = $this->resolveToolContext($env, (string) $this->option('context') ?: null);
        $names = ToolInstance::forInstance(ClusterTool::SUPABASE, $instance);
        $data = Kubectl::forContext($context)->raw(['get', 'secret', $names->secret(), '-n', $names->namespace(), '-o', 'json', '--ignore-not-found'])->json()['data'] ?? [];

        $username = base64_decode((string) ($data['dashboard-username'] ?? ''));
        $password = base64_decode((string) ($data['dashboard-password'] ?? ''));
        $publishable = base64_decode((string) ($data['publishable-key'] ?? ''));
        $secret = base64_decode((string) ($data['secret-key'] ?? ''));
        $s3KeyId = base64_decode((string) ($data['s3-protocol-access-key-id'] ?? ''));
        $s3Secret = base64_decode((string) ($data['s3-protocol-access-key-secret'] ?? ''));

        $credentials = $username !== '' && $password !== ''
            ? ['admin_username' => $username, 'admin_password' => $password]
            : [];

        // The sb_ keys only work once their key pair exists (the gateway checks for it).
        if ($publishable !== '' && $secret !== '' && isset($data['anon-key-asymmetric'])) {
            $credentials += ['publishable_key' => $publishable, 'secret_key' => $secret];
        }

        if ($s3KeyId !== '' && $s3Secret !== '' && $host !== null) {
            $credentials += [
                's3_endpoint' => "https://{$host}/storage/v1/s3",
                // Storage's REGION, which signed requests must name.
                's3_region' => 'stub',
                's3_access_key_id' => $s3KeyId,
                's3_secret_access_key' => $s3Secret,
            ];
        }

        return $credentials !== [] ? $credentials : null;
    }
}
