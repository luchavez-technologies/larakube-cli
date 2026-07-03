<?php

namespace App\Data;

use App\Enums\DeploymentStrategy;
use App\Enums\IngressController;
use App\Enums\LaravelFeature;
use Spatie\LaravelData\Data;

/**
 * Per-environment configuration overrides. Lives inside
 * ConfigData::$environments as a map keyed by env name (local, staging,
 * production).
 *
 * Carries the fields that genuinely vary per environment:
 *   - ingress: optional override of the project-level default
 *   - managed: services LaraKube should NOT deploy in this env (because an
 *     external provider handles them — e.g. RDS Postgres in production)
 *   - hosts: service → external hostname map
 *   - addFeatures: explicit opt-in for a feature whose enum default would
 *     otherwise exclude it from this env (rare)
 *   - excludeFeatures: explicit opt-out for a feature whose enum default
 *     would otherwise include it in this env (rare)
 *
 * Common-case features (horizon, queues, reverb, scheduler, ssr, boost,
 * mailpit, etc.) live in ConfigData::$features at the project level. Each
 * LaravelFeature enum case declares its natural environment scope via
 * appliesToEnvironment(), and ConfigData::getFeatures($env) filters by it.
 * That keeps blueprints lean — most projects need neither addFeatures
 * nor excludeFeatures.
 */
class EnvironmentData extends Data
{
    public function __construct(
        public ?IngressController $ingress = null,
        /**
         * Deployment strategy for this env (single-node vs multi-node-HA).
         * Lets a budget-tiered setup run e.g. staging single-node and
         * production multi-node-HA. Falls back to the project-level
         * strategy when null.
         */
        public ?DeploymentStrategy $strategy = null,
        /**
         * Services external to the cluster in this environment (e.g.
         * managed Postgres on RDS in production). LaraKube skips
         * deployment for these.
         *
         * @var array<int, string>
         */
        public array $managed = [],
        /**
         * Services backed by the shared Plex "Commons" in this env (a
         * specialisation of `managed`). Their connection (host/db/user/password)
         * is written into .env by `plex:join`; env-sync SKIPS recomputing these
         * components so a `heal`/regenerate can't clobber the Commons values
         * back to the in-namespace defaults.
         *
         * @var array<int, string>
         */
        public array $plex = [],
        /**
         * Service → external hostname map. Example for production:
         *   ['web' => 'app.example.com', 'reverb' => 'ws.example.com']
         *
         * @var array<string, string>
         */
        public array $hosts = [],
        /**
         * Extra hostnames that route to the SAME web pod as `hosts['web']` —
         * for a Laravel app using subdomain route groups
         * (https://laravel.com/docs/routing#route-group-subdomain-routing),
         * or simply a second marketing domain. Each gets its own Ingress
         * rule (same backend) and, in the cloud, its own independently
         * obtained TLS cert. `hosts['web']` stays the one canonical
         * APP_URL/ASSET_URL; these are additional routable domains, not
         * alternates for it.
         *
         * @var array<int, string>
         */
        public array $additionalWebHosts = [],
        /**
         * Connection config for deploying this env to a remote cluster (VPS SSH
         * or a managed kube-context). Null for envs not (yet) wired to a cluster
         * — local never has one. Spatie Data auto-casts this nested object from a
         * JSON array.
         */
        public ?CloudData $cloud = null,
        /**
         * Features to enable in this env that would otherwise be excluded
         * by their enum's appliesToEnvironment() rule.
         *
         * @var array<int, LaravelFeature>
         */
        public array $addFeatures = [],
        /**
         * Features to disable in this env that would otherwise be enabled
         * by their enum's appliesToEnvironment() rule.
         *
         * @var array<int, LaravelFeature>
         */
        public array $excludeFeatures = [],
        // --- ☁️ Managed-K8s overlay knobs (EKS/GKE/AKS) ---
        // All optional; each no-ops to today's Single-Node-Hero output when
        // unset, so existing blueprints and the snapshot suite are unchanged.
        /**
         * Override the derived `{name}-{env}` namespace, so the overlay can
         * land in an existing cluster namespace (e.g. `myapp` on EKS).
         */
        public ?string $namespace = null,
        /**
         * ServiceAccount name for the app pods. Null = today's behavior (no
         * SA on user pods). Set for IRSA/Workload-Identity setups.
         */
        public ?string $serviceAccount = null,
        /**
         * Annotations for the generated ServiceAccount — e.g.
         * `eks.amazonaws.com/role-arn` for IRSA. Only emitted when
         * $serviceAccount is set.
         *
         * @var array<string, string>
         */
        public array $serviceAccountAnnotations = [],
        /**
         * Image pull secret name. Defaults to `ghcr-login` (Single-Node-Hero)
         * when null and not omitted. Set to point at a different secret.
         */
        public ?string $imagePullSecret = null,
        /**
         * Drop the imagePullSecrets block entirely — for clusters that pull
         * via the node role/IRSA (e.g. ECR on EKS) and need no secret.
         */
        public bool $omitImagePullSecret = false,
        /**
         * Extra ingress annotations merged into the env's ingress-patch —
         * ACM cert ARN, security groups, ALB conditions/actions, etc. Raw
         * passthrough (dumb merge); the controller's defaults still apply.
         *
         * @var array<string, string>
         */
        public array $ingressAnnotations = [],
        /**
         * StorageClass name for PVCs in this env. Null = cluster default
         * (snapshot-stable). Provider-specific:
         *   DOKS: "do-block-storage"
         *   EKS: "gp2" or "gp3"
         *   GKE: "standard"
         *   AKS: "default" or "managed-premium"
         * When migrating between clouds, simply update this value.
         */
        public ?string $storageClass = null,
        /**
         * Enable cert-manager TLS on this environment. When set, a ClusterIssuer
         * annotation is added to the ingress for automatic certificate provisioning.
         * Requires cert-manager to be installed on the cluster first.
         * Example: "letsencrypt-prod" (must match an existing ClusterIssuer name).
         * Null = no automatic TLS (start on HTTP, or use the raw ingressAnnotations knob).
         */
        public ?string $certManagerIssuer = null,
        /**
         * Container registry configuration for CI/CD image builds and pushes.
         * Null = no registry (local builds only, no CI deploy).
         * Example: {"provider": "ghcr"} or {"provider": "dockerhub", "image": "owner/repo"}
         */
        public ?RegistryData $registry = null,
        /**
         * Opt-in shared (ReadWriteMany) storage for THIS env on multi-node. Default
         * false → multi-node app pods are stateless (per-pod emptyDir) and state is
         * externalized. Set true for apps that genuinely need a shared cross-node
         * folder (e.g. a sitemap written by a worker and served by web): LaraKube
         * points the shared PVC at the in-cluster NFS StorageClass (RWX) instead of
         * an emptyDir. Requires the NFS provisioner — `larakube cloud:init:nfs`.
         */
        public bool $sharedStorage = false,
        /**
         * Per-component pod resources (hybrid): an optional "default" block plus
         * optional per-pod overrides, e.g.
         *   {"default": {"requests": {"cpu":"100m","memory":"256Mi"}, "limits": {...}},
         *    "horizon": {"limits": {"memory": "2Gi"}}}
         * Merged over the conservative code default by ConfigData::getResources();
         * a component override merges into "default" (only the keys it sets win).
         * Edit via `larakube resources` — not by hand.
         *
         * @var array<string, mixed>
         */
        public array $resources = [],
        /**
         * Mark this environment for offline / air-gapped distribution.
         * When true, `bundle:build` will auto-select this env and the CLI
         * ships a standalone Kustomize binary inside the tarball so the
         * remote server never depends on its host kubectl version.
         */
        public bool $offline = false,
        /**
         * Persistent tunnel configuration for environments behind CGNAT or
         * without open inbound ports. Null = no tunnel (direct ingress only).
         * Configure with `larakube cloud:configure:tunnel <env>`.
         */
        public ?TunnelData $tunnel = null,
    ) {}
}
