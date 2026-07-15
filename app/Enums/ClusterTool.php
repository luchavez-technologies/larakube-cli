<?php

namespace App\Enums;

enum ClusterTool: string
{
    case FLOW = 'flow';
    case SHEETS = 'sheets';
    case PASSWORDS = 'passwords';
    case MONITOR = 'monitor';
    case SECRETS = 'secrets';
    case ERRORS = 'errors';
    case UPTIME = 'uptime';
    case GIT = 'git';
    case VPN = 'vpn';

    public function getLabel(): string
    {
        return match ($this) {
            self::FLOW => 'N8N (Workflow Automation)',
            self::SHEETS => 'NocoDB (Spreadsheet Database)',
            self::PASSWORDS => 'Vaultwarden (Password Manager)',
            self::MONITOR => 'Grafana + Loki + Prometheus (Monitoring Stack)',
            self::SECRETS => 'Infisical (Secrets Manager)',
            self::ERRORS => 'GlitchTip (Error Tracking)',
            self::UPTIME => 'Uptime Kuma (Status Pages)',
            self::GIT => 'Gitea (Git Forge & CI/CD)',
            self::VPN => 'NetBird (Zero-Trust VPN Mesh)',
        };
    }

    /**
     * Get an associative array of value => label for prompts.
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $tool) {
            $options[$tool->value] = $tool->getLabel();
        }

        return $options;
    }
}
