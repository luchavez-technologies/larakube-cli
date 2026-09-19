<?php

namespace App\Traits;

use App\Data\ResourceRef;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Services\Kubectl;
use App\Services\ToolRegistry;

use function Laravel\Prompts\select;

/**
 * tool:proxy / tool:unproxy: switch one tool instance's Cloudflare proxy by
 * annotating its Ingresses (ExternalDNS updates the record; no pod restarts)
 * and remember the choice in the registry, so `{tool}:init` keeps it.
 *
 * Needs ChecksCloudflareProxy, DeploysClusterTool, LaraKubeOutput.
 */
trait TogglesToolProxy
{
    private const string PROXIED_ANNOTATION = 'external-dns.alpha.kubernetes.io/cloudflare-proxied';

    protected function toggleToolProxy(bool $proxied): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');
        if ($env === 'local') {
            $this->laraKubeError('Local tools are never proxied; pass a cloud environment.');

            return 1;
        }

        $cluster = Kubectl::forContext($this->resolveToolContext($env, (string) ($this->option('context') ?: '') ?: null));
        $registry = ToolRegistry::on($cluster);

        $row = $this->pickToolRow($registry, (string) ($this->option('domain') ?: ''));
        if ($row === null) {
            return 1;
        }

        $tool = ClusterTool::from($row['tool']);
        $host = (string) $row['host'];

        if (($row['proxied'] ?? null) === $proxied) {
            $this->laraKubeInfo("{$host} is already ".($proxied ? 'proxied through Cloudflare.' : 'DNS-only.'));

            return 0;
        }

        if ($proxied) {
            if (($reason = self::proxyRefusal($tool, $this->servesVpnOnly($cluster, $tool, $host))) !== null) {
                $this->laraKubeError("Not proxying {$host}: {$reason}");

                return 1;
            }

            if (! $this->proxyChecksPass($env, $cluster->prefix(), [$host])) {
                return 1;
            }
        }

        $ingresses = $this->ingressesServing($cluster, $tool->namespace(), $host);
        if ($ingresses === []) {
            $this->laraKubeError("No Ingress in {$tool->namespace()} serves {$host}. Re-run `larakube {$tool->initCommand()} {$env} --domain={$host}`.");

            return 1;
        }

        foreach ($ingresses as $name) {
            $annotation = $proxied ? self::PROXIED_ANNOTATION.'=true' : self::PROXIED_ANNOTATION.'-';
            if (! $this->kubectlStep("Updating Ingress {$name}...", fn () => $cluster->raw(['annotate', 'ingress', $name, '-n', $tool->namespace(), $annotation, '--overwrite']))) {
                return 1;
            }
        }

        $registry->register($tool, ['proxied' => $proxied], (string) ($row['instance'] ?? ToolInstance::forHost($tool, $host)->instance));

        $this->laraKubeInfo($proxied
            ? "✅ {$host} is now proxied through Cloudflare. ExternalDNS updates its record within a minute."
            : "✅ {$host} is DNS-only again. ExternalDNS updates its record within a minute.");

        return 0;
    }

    /** @return array<string, mixed>|null the registry row for --domain, or a picked one */
    private function pickToolRow(ToolRegistry $registry, string $domain): ?array
    {
        $rows = array_values(array_filter($registry->rows(), fn (array $row) => ($row['host'] ?? '') !== ''));
        if ($rows === []) {
            $this->laraKubeError('No tools with a host are registered on this cluster.');

            return null;
        }

        if ($domain !== '') {
            $host = ToolInstance::normalizeHost($domain);
            foreach ($rows as $row) {
                if ($row['host'] === $host || in_array($host, $row['aliases'] ?? [], true)) {
                    return $row;
                }
            }

            $this->laraKubeError("No tool is registered at {$host}.");
            $this->line('  <fg=gray>Registered hosts:</> '.implode(', ', array_map(fn (array $row) => $row['host'], $rows)));

            return null;
        }

        if ($this->option('no-interaction')) {
            $this->laraKubeError('Pass --domain=<host> to say which tool.');

            return null;
        }

        $host = select(
            label: 'Which tool?',
            options: array_combine(
                array_map(fn (array $row) => $row['host'], $rows),
                array_map(fn (array $row) => "{$row['host']} ({$row['tool']})", $rows),
            ),
        );

        return array_values(array_filter($rows, fn (array $row) => $row['host'] === $host))[0];
    }

    /** @return list<string> Ingress names in $namespace with a rule for $host */
    private function ingressesServing(Kubectl $cluster, string $namespace, string $host): array
    {
        $names = [];
        foreach ($cluster->list('ingress', $namespace) as $ingress) {
            foreach ($ingress['spec']['rules'] ?? [] as $rule) {
                if (($rule['host'] ?? null) === $host) {
                    $names[] = (string) $ingress['metadata']['name'];
                    break;
                }
            }
        }

        return $names;
    }

    /** Whether the host's Ingress sends traffic through the VPN-only middleware. */
    private function servesVpnOnly(Kubectl $cluster, ClusterTool $tool, string $host): bool
    {
        foreach ($this->ingressesServing($cluster, $tool->namespace(), $host) as $name) {
            $ingress = $cluster->get(new ResourceRef('Ingress', $name, $tool->namespace()));
            $middlewares = (string) ($ingress['metadata']['annotations']['traefik.ingress.kubernetes.io/router.middlewares'] ?? '');
            if (str_contains($middlewares, 'vpn-only')) {
                return true;
            }
        }

        return false;
    }
}
