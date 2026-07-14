<?php

namespace App\Data;

use Spatie\LaravelData\Data;

/**
 * Connection config for the cluster an environment deploys to. Carries ONE of
 * two identities, depending on how the cluster is reached:
 *
 *   • VPS      — $ip (+ SSH user/port/key). We provision k3s on it ourselves and
 *                the kube-context is derived as `larakube-{ip}`.
 *   • Managed  — $context (DOKS/EKS/GKE/AKS/…). No SSH; the provider's CLI wrote
 *                a kube-context into ~/.kube/config and we target it verbatim.
 *
 * Lives on EnvironmentData::$cloud so cloud config stays attached to the env it
 * describes.
 */
class CloudData extends Data
{
    public function __construct(
        public ?string $ip = null,
        public ?string $user = 'larakube',
        public ?int $port = 22,
        public ?string $key = null,
        /**
         * Kube-context name for a MANAGED cluster (DOKS/EKS/GKE/AKS/Civo/LKE…),
         * as written into ~/.kube/config by the provider's CLI — e.g.
         * `do-nyc1-app`, an EKS cluster ARN, or `gke_proj_zone_app`. Mutually
         * exclusive with $ip: a managed cluster has no SSH/IP and is targeted by
         * this context as-is. Null for VPS environments (their context is derived
         * from $ip instead). We store the string verbatim — no per-provider
         * parsing — so any provider that can emit a kube-context is supported.
         */
        public ?string $context = null,
        /**
         * The managed Kubernetes provider for a $context-based env (doks / eks /
         * gke / aks / civo / lke / custom). Drives sensible defaults like the
         * env's storageClass. Null for VPS environments.
         */
        public ?string $provider = null,
        /**
         * Target node CPU architecture override for the image build — `amd64` or
         * `arm64`. Null = auto-detect (SSH `uname -m` for a VPS, the cluster
         * nodes' arch for a managed $context), falling back to amd64. Set this to
         * skip detection or to force a platform (e.g. an arm64 Pi / Graviton /
         * Ampere node, or a heterogeneous cluster where you want to pin one).
         */
        public ?string $arch = null,
        /**
         * When the CI deploy credential (the namespace-scoped Secret-bound token
         * uploaded as {ENV}_KUBECONFIG by `cloud:configure --only=ci`) was last minted —
         * ISO-8601. Null = no scoped CI credential has been issued yet. Presence
         * is the "scoped CI is set up" marker; lets us warn when a token is stale.
         */
        public ?string $rbacGrantedAt = null,
        /**
         * This VPS's own NetBird overlay IP, once cloud:harden has joined it to
         * the project's VPN — SSH-consuming commands prefer this over $ip once
         * set. Deliberately separate from $ip (never overwritten): $ip stays the
         * public address the `larakube-{ip}` kube-context is derived from, and
         * cloud:harden's VPN-CIDR restriction makes that public address
         * unreachable for SSH/6443 going forward regardless of who's asking —
         * traffic to a peer's public IP never routes through the NetBird
         * tunnel, only traffic to its own overlay IP does.
         */
        public ?string $vpnIp = null,
    ) {
        $this->key = $key ?? ($_SERVER['HOME'] ?? '').'/.ssh/id_rsa';
    }

    /**
     * True when this env targets a MANAGED cluster (a stored kube-context)
     * rather than a VPS we SSH into. The presence of $context is the signal.
     */
    public function isManaged(): bool
    {
        return $this->context !== null && $this->context !== '';
    }
}
