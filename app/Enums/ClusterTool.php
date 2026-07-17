<?php

namespace App\Enums;

enum ClusterTool: string
{
    public function getLabel(): string
    {
        return match ($this) {
            self::FLOW => 'N8N or Windmill (Workflow Automation)',
            self::SHEETS => 'Baserow or NocoDB (Spreadsheet Database)',
            self::PASSWORDS => 'Vaultwarden (Password Manager)',
            self::MONITOR => 'Grafana + Loki + Prometheus (Monitoring Stack)',
            self::SECRETS => 'Infisical (Secrets Manager)',
            self::ERRORS => 'GlitchTip (Error Tracking)',
            self::UPTIME => 'Uptime Kuma (Status Pages)',
            self::GIT => 'Gitea (Git Forge & CI/CD)',
            self::VPN => 'NetBird (Zero-Trust VPN Mesh)',
            self::INSIGHTS => 'Metabase (Business Intelligence & Dashboards)',
            self::DNS => 'ExternalDNS (Automated Cloudflare Records)',
            self::MAIL => 'Stalwart (Mail Server)',
            self::DESK => 'FreeScout (Help Desk & Shared Inbox)',
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

    /**
     * SMTP-consumer wiring schema for tools that send email: the Deployment (and
     * its namespace) to patch, the Secret that holds the credentials (its keys
     * ARE the target env var names, so `kubectl set env --from=secret` maps them
     * 1:1), any static env, and a logical => env-var-name map the wirer fills
     * from the Stalwart endpoint. null when the tool doesn't send email. This is
     * the single hook a new tool implements to become wireable by `mail:wire` /
     * `tool:add` — no per-tool wiring code anywhere else.
     *
     * @return array{deployment: string, namespace: string, secret: string, static?: array<string, string>, vars: array<string, string>}|null
     */
    public function smtpEnv(): ?array
    {
        return match ($this) {
            self::SHEETS => [
                // Baserow is the default Sheet engine. mail:wire patches the
                // Baserow Deployment; EMAIL_SMTP enables SMTP, EMAIL_SMTP_USE_SSL
                // matches Stalwart's implicit-TLS submission on 465 (its default).
                'deployment' => 'sheet-baserow',
                'namespace' => 'larakube-shared',
                'secret' => 'sheet-baserow-smtp',
                'static' => [
                    'EMAIL_SMTP' => 'yes',
                    'EMAIL_SMTP_USE_SSL' => 'yes',
                ],
                'vars' => [
                    'host' => 'EMAIL_SMTP_HOST',
                    'port' => 'EMAIL_SMTP_PORT',
                    'user' => 'EMAIL_SMTP_USER',
                    'password' => 'EMAIL_SMTP_PASSWORD',
                    'from' => 'FROM_EMAIL',
                ],
            ],
            self::FLOW => [
                'deployment' => 'flow-n8n',
                'namespace' => 'larakube-shared',
                'secret' => 'flow-n8n-smtp',
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
            ],
            self::PASSWORDS => [
                'deployment' => 'vaultwarden',
                'namespace' => 'larakube-vault',
                'secret' => 'vaultwarden-smtp',
                'static' => [
                    'SMTP_SECURITY' => 'force_tls',
                ],
                'vars' => [
                    'host' => 'SMTP_HOST',
                    'port' => 'SMTP_PORT',
                    'user' => 'SMTP_USERNAME',
                    'password' => 'SMTP_PASSWORD',
                    'from' => 'SMTP_FROM',
                ],
            ],
            default => null,
        };
    }
    case FLOW = 'flow';
    case SHEETS = 'sheets';
    case PASSWORDS = 'passwords';
    case MONITOR = 'monitor';
    case SECRETS = 'secrets';
    case ERRORS = 'errors';
    case UPTIME = 'uptime';
    case GIT = 'git';
    case VPN = 'vpn';
    case INSIGHTS = 'insights';
    case DNS = 'dns';
    case MAIL = 'mail';
    case DESK = 'desk';
}
