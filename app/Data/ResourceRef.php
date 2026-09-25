<?php

namespace App\Data;

/**
 * One namespaced Kubernetes object, identified the way `kubectl` names it.
 */
final readonly class ResourceRef
{
    public function __construct(
        public string $kind,
        public string $name,
        public string $namespace,
    ) {}

    /** `secret/notes-secrets`, the form `kubectl delete` takes. */
    public function ref(): string
    {
        return strtolower($this->kind).'/'.$this->name;
    }

    /**
     * `larakube-shared-outline-vpn-only@kubernetescrd` — how a Traefik
     * Ingress annotation names a Middleware. The provider qualifies it with
     * the namespace it actually lives in, so this is the only correct way to
     * reference one; a hand-written literal drifts from the Middleware
     * `ensureVpnMiddleware()` creates the moment a tool's naming changes.
     */
    public function traefikMiddleware(): string
    {
        return "{$this->namespace}-{$this->name}@kubernetescrd";
    }

    /** Unique across namespaces, for comparing sets of resources. */
    public function key(): string
    {
        return "{$this->namespace}/".$this->ref();
    }
}
