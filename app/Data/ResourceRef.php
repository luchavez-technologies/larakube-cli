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

    /** Unique across namespaces, for comparing sets of resources. */
    public function key(): string
    {
        return "{$this->namespace}/".$this->ref();
    }
}
