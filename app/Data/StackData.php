<?php

namespace App\Data;

use Spatie\LaravelData\Data;

/**
 * One OpenTofu-managed infrastructure stack — a single droplet (VPS) or a single
 * managed cluster (DOKS). Decoupled from environments: many environments (across
 * one or many projects) can bind to the same stack, which is why the registry
 * lives in the GLOBAL config (~/.larakube) and not in any one project repo.
 *
 * The Tofu working dir + (encrypted) state live at ~/.larakube/tofu/{name}/.
 */
class StackData extends Data
{
    public function __construct(
        /** Stable slug, also the Tofu workdir name (e.g. "larakube-vps-acme"). */
        public string $name,
        /** Cloud provider — "do" for now. */
        public string $provider = 'do',
        /** "vps" (droplet + k3s) or "doks" (managed cluster). */
        public string $kind = 'vps',
        /** Provider region slug, e.g. "sgp1". */
        public ?string $region = null,
        /** Resulting kube-context once provisioned (larakube-{ip} or the DOKS context). */
        public ?string $context = null,
        /** Public IP for a VPS stack (null for managed). */
        public ?string $ip = null,
        /**
         * Environments bound to this stack, as "appName/env" tags. Lets
         * `cloud:destroy` warn when something still deploys here. Best-effort
         * (a binding recorded from another machine won't appear here).
         *
         * @var array<int, string>
         */
        public array $bindings = [],
        /** Cloud account identifier: AWS profile name or GCP account email. */
        public ?string $account = null,
        /** Cloud project identifier: GCP project ID. */
        public ?string $projectId = null,
        /** ISO-8601 creation timestamp. */
        public ?string $createdAt = null,
    ) {}

    /** Add an "appName/env" binding (idempotent). */
    public function bind(string $appName, string $environment): void
    {
        $tag = $appName.'/'.$environment;
        if (! in_array($tag, $this->bindings, true)) {
            $this->bindings[] = $tag;
        }
    }
}
