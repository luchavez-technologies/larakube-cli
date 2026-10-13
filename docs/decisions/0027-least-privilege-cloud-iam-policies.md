# 0027 — Prioritize least privilege and minimal IAM access across all cloud integrations

**Status:** Accepted (2026-10-13)

## Context

LaraKube provisions infrastructure (EC2, EKS, DOKS, GKE) and companion services (ExternalDNS, Traefik DNS-01 challenge, backup storage) requiring cloud provider credentials. Operators—especially in enterprise, client, or regulated environments—cannot and should not grant blanket administrative privileges (`AdministratorAccess`, `Owner`) to external automation tooling.

Previously:
- AWS single-node VPS required only EC2 permissions, but operators lacked clarity on what was needed, leading to confusion or insecure over-permissioning.
- Managed EKS required additional IAM and EKS cluster/nodegroup permissions.
- Companion DNS tooling like ExternalDNS and Traefik Let's Encrypt DNS challenge require DNS provider credentials (such as AWS Route 53 or Google Cloud DNS).

Without explicit minimal permission manifests, users are tempted to attach coarse full-access roles, creating an unnecessary security risk.

## Decision

**1. LaraKube strictly prioritizes least privilege and minimal IAM access.**
Across all cloud provider integrations (AWS, GCP, DigitalOcean, Hetzner, Cloudflare) and companion tools (Route 53, Google Cloud DNS, R2/S3 backups), LaraKube will always define, document, and surface the exact, minimal set of permissions required to complete the task.

**2. In-app minimal policy generation.**
Where custom IAM policies are required (e.g. AWS IAM), the desktop and CLI interfaces must:
- Provide exact, scoped JSON policy statements rather than directing operators to broad administrative roles.
- Surface interactive copy utilities (`CopyButton`) in the UI that assemble the minimal policy tailored to the operator's active choices (e.g., EC2 only, EC2 + EKS, EC2 + Route 53, or EC2 + EKS + Route 53).

**3. Granular Route 53 least privilege.**
When configuring AWS Route 53 for ExternalDNS or Traefik DNS challenge:
- Do NOT require `AdministratorAccess` or `AmazonRoute53FullAccess`.
- The minimal policy statement covers strictly the operations needed by ExternalDNS and Traefik:
  - `route53:ListHostedZones` and `route53:ListResourceRecordSets` (read/discover zones).
  - `route53:ChangeResourceRecordSets` (create/update/delete DNS verification and app records, scoped to target hosted zones).
  - `route53:GetChange` (poll DNS record propagation status).

```json
{
  "Sid": "LaraKubeRoute53LeastPrivilege",
  "Effect": "Allow",
  "Action": [
    "route53:ListHostedZones",
    "route53:ListResourceRecordSets",
    "route53:ChangeResourceRecordSets",
    "route53:GetChange"
  ],
  "Resource": [
    "arn:aws:route53:::hostedzone/*",
    "arn:aws:route53:::change/*",
    "*"
  ]
}
```

**4. Separation of infrastructure and companion permissions.**
Where possible, operators are encouraged to use dedicated, least-privileged credentials for specific tools (e.g. a dedicated Route 53 IAM user for ExternalDNS) rather than reusing full infrastructure provisioning credentials across runtime workloads.

## Consequences

- Operators can confidently deploy LaraKube in locked-down AWS or GCP accounts without security review friction.
- UI components like `AwsPolicyHelper` must reactively compose policy JSON based on the user's selected infrastructure and companion options.
- Any future provider or tool integration must document its minimal policy alongside 1-click convenience setups.
