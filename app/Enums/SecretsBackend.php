<?php

namespace App\Enums;

use App\Contracts\HasDockerImage;
use App\Contracts\HasLabel;
use App\Data\ConfigData;

enum SecretsBackend: string implements HasDockerImage, HasLabel
{
    public function getLabel(): ?string
    {
        return 'OpenBao';
    }

    public function getDockerImage(?ConfigData $config = null): string
    {
        return 'openbao/openbao:2.6.1';
    }

    public function getDefaultPort(): int
    {
        return 8200;
    }

    public function getNamespace(): string
    {
        return 'larakube-secrets';
    }

    public function getBootstrapSecretName(): string
    {
        return 'openbao-bootstrap';
    }

    public function getDeploymentName(): string
    {
        return 'openbao-backend';
    }

    public function getCrdTemplateName(): string
    {
        return 'k8s.secrets.eso-sync';
    }

    /**
     * The three OpenBao ACL policies shared by every auth method that can
     * reach it — OIDC roles (SsoWireCommand::wireOpenBaoOidc()) and the
     * baseline userpass admin (SecretsInitCommand) both write these same
     * definitions, rather than each keeping their own copy that could drift
     * out of sync and leave "SSO admin" and "local admin" with silently
     * different actual permissions.
     *
     * @return array<string, string>
     */
    public function policies(): array
    {
        return [
            'admin-policy' => 'path "secret/*" { capabilities = ["create", "read", "update", "delete", "list"] }'
                ."\n".'path "sys/mounts" { capabilities = ["read", "list"] }'
                ."\n".'path "sys/internal/ui/mounts" { capabilities = ["read"] }'
                ."\n".'path "sys/internal/ui/mounts/*" { capabilities = ["read"] }',
            'operator-policy' => 'path "secret/data/production/*" { capabilities = ["read", "list"] }'
                ."\n".'path "secret/metadata/production/*" { capabilities = ["read", "list"] }'
                ."\n".'path "sys/internal/ui/mounts" { capabilities = ["read"] }'
                ."\n".'path "sys/internal/ui/mounts/*" { capabilities = ["read"] }',
            'auditor-policy' => 'path "secret/metadata/*" { capabilities = ["read", "list"] }'
                ."\n".'path "sys/audit" { capabilities = ["read"] }'
                ."\n".'path "sys/health" { capabilities = ["read"] }'
                ."\n".'path "sys/internal/ui/mounts" { capabilities = ["read"] }'
                ."\n".'path "sys/internal/ui/mounts/*" { capabilities = ["read"] }',
            // Bound to ESO's own controller ServiceAccount via Vault Kubernetes
            // auth (auth/kubernetes/role/eso-controller) — deliberately narrow:
            // read-only on rotated static creds, nothing else. Any namespace's
            // VaultDynamicSecret can use this same role with zero secret
            // duplication, since ESO's controller identity is shared cluster-wide
            // already (same trust boundary as the existing ClusterSecretStore).
            'db-static-creds-reader-policy' => 'path "database/static-creds/*" { capabilities = ["read"] }',
        ];
    }
    case OPENBAO = 'openbao';
}
