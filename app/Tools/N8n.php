<?php

namespace App\Tools;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasCommonsDatabases;
use App\Contracts\HasDeploymentBaseName;
use App\Contracts\HasImages;
use App\Contracts\HasSmtpWiring;
use App\Contracts\HasToolSettings;
use App\Contracts\HasVpnWiring;
use App\Data\ToolInstance;
use App\Data\ToolSetting;
use App\Enums\ClusterTool;
use App\Enums\SecretKind;
use App\Tools\Concerns\PinsImages;

/**
 * n8n, one of the FLOW engines. SSO is an n8n Enterprise feature, so it has
 * no oidcEnv(); owners sign in with n8n's own accounts.
 */
final class N8n implements ClusterToolVendor, HasCommonsDatabases, HasDeploymentBaseName, HasImages, HasSmtpWiring, HasToolSettings, HasVpnWiring
{
    use PinsImages;

    public const string ENGINE = 'n8n';

    public function getLabel(): string
    {
        return 'n8n';
    }

    public function images(): array
    {
        // The runners image must be the same version as n8n's own.
        return [
            'n8n' => 'n8nio/n8n:2.47.7',
            'runners' => 'docker.io/n8nio/runners:2.47.7',
        ];
    }

    public function settings(): array
    {
        return [
            new ToolSetting(
                key: 'N8N_UNVERIFIED_PACKAGES_ENABLED',
                label: 'Allow unverified community nodes',
                help: 'Lets n8n install and load community nodes n8n has not reviewed. n8n 3.0 turns this off by default; nodes already installed stop loading when it is off.',
                type: 'boolean',
                default: 'true',
            ),
            new ToolSetting(
                key: 'N8N_RUNNERS_TASK_TIMEOUT',
                label: 'Code node time limit',
                help: 'How long one Code node may run before n8n stops it. n8n 3.0 lowers the default to 60 seconds.',
                type: 'number',
                default: '300',
                unit: 'seconds',
            ),
        ];
    }

    public function defaultEnv(): array
    {
        return [
            ...array_column(array_map(fn (ToolSetting $setting): array => [$setting->key, $setting->default], $this->settings()), 1, 0),
            // n8n 3.0's smaller unzip limits for the Compression node, taken early.
            'N8N_COMPRESSION_NODE_MAX_DECOMPRESSED_SIZE_BYTES' => '268435456',
            'N8N_COMPRESSION_NODE_MAX_ZIP_ENTRIES' => '1000',
        ];
    }

    public function baseDeploymentName(): string
    {
        return 'n8n';
    }

    public function canonicalComponentName(): string
    {
        return 'n8n';
    }

    public function commonsDatabaseList(): array
    {
        return ['n8n'];
    }

    public function canonicalDatabaseList(): array
    {
        return ['n8n'];
    }

    public function vpnMiddlewareTarget(?string $instance = null): ?array
    {
        return [
            'name' => ($instance === null || $instance === '')
                ? 'n8n-vpn-only'
                : ToolInstance::forInstance(ClusterTool::N8N, $instance, self::ENGINE)->name('vpn-only'),
            'namespace' => ClusterTool::N8N->namespace(),
        ];
    }

    public function smtpEnv(?string $instance = null): ?array
    {
        $names = ($instance === null || $instance === '') ? null : ToolInstance::forInstance(ClusterTool::N8N, $instance, self::ENGINE);
        // Without an instance there is no ToolInstance to ask, but the name
        // still has to follow the tool's current naming generation — reading
        // baseDeploymentName() directly would pin it to the pre-migration one.
        $base = ClusterTool::N8N->deploymentName(engine: self::ENGINE);

        return [
            'deployment' => $names?->deployment() ?? $base,
            'secret' => $names?->secret(SecretKind::SMTP) ?? $base.'-'.SecretKind::SMTP->value,
            'static' => [
                'N8N_EMAIL_MODE' => 'smtp',
                'N8N_SMTP_SSL' => 'true',
                'N8N_SMTP_STARTTLS' => 'false',
            ],
            'vars' => [
                'host' => 'N8N_SMTP_HOST',
                'port' => 'N8N_SMTP_PORT',
                'user' => 'N8N_SMTP_USER',
                'password' => 'N8N_SMTP_PASS',
                'from' => 'N8N_SMTP_SENDER',
            ],
        ];
    }
}
