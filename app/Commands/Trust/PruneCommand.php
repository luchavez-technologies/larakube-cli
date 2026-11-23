<?php

namespace App\Commands\Trust;

use App\Services\Kubectl;
use App\Traits\EmitsJsonOutput;
use App\Traits\InteractsWithTraefik;
use App\Traits\InteractsWithTrust;
use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\confirm;

use LaravelZero\Framework\Commands\Command;

class PruneCommand extends Command
{
    use EmitsJsonOutput, InteractsWithTraefik, InteractsWithTrust, LaraKubeOutput;

    protected $signature = 'trust:prune
        {--dry-run : List what would be removed, and remove nothing}
        {--force   : Remove without asking (required when run without a terminal)}
        {--json    : Emit one machine-readable JSON result on stdout}';

    protected $description = 'Remove local certificates no local app uses any more (old projects, removed apps, an old TLD)';

    public function handle(): int
    {
        if ($this->option('json')) {
            $this->enableJsonMode();
        }

        $this->renderHeader();

        $cluster = Kubectl::current();
        $context = trim($cluster->raw(['config', 'current-context'])->output);

        if (! Kubectl::isLocalContextName($context)) {
            return $this->result(false, [], "'{$context}' is not a local cluster. Switch to your local one first: local certificates are only used there.");
        }

        $served = $this->servedHosts($cluster);

        if ($served === null) {
            return $this->result(false, [], 'Could not read the local cluster\'s ingresses, so it cannot tell which certificates are in use. Is the cluster running?');
        }

        $unused = [];
        foreach ($this->getAllLocalAppCerts() as $name => $paths) {
            $hosts = $this->certHosts($paths['crt']);

            if (array_intersect($hosts, $served) === []) {
                $unused[$name] = ['name' => $name, 'hosts' => $hosts, 'paths' => $paths];
            }
        }

        $report = array_values(array_map(fn (array $cert): array => ['name' => $cert['name'], 'hosts' => $cert['hosts']], $unused));

        if ($unused === []) {
            $this->laraKubeInfo('Every local certificate is in use. Nothing to remove.');

            return $this->result(true, []);
        }

        $this->laraKubeInfo(count($unused).' local certificate(s) serve no app on this cluster:');
        foreach ($report as $cert) {
            $this->laraKubeLine('  • '.($cert['hosts'] !== [] ? implode(', ', $cert['hosts']) : $cert['name']));
        }
        $this->laraKubeLine('  <fg=gray>LaraKube issues a certificate again when its app is installed or `up` runs.</>');

        if ($this->option('dry-run')) {
            return $this->result(true, $report, dryRun: true);
        }

        if (! $this->option('force')) {
            if ($this->option('no-interaction') || $this->option('json')) {
                return $this->result(false, [], 'Not removing without --force.');
            }

            if (! confirm('Remove them?', false)) {
                return $this->result(false, [], 'Cancelled.');
            }
        }

        foreach ($unused as $cert) {
            @unlink($cert['paths']['crt']);
            @unlink($cert['paths']['key']);
            @unlink($this->getAppCertTldPath($cert['name']));
        }

        // Traefik serves every certificate file in its pool: give it the smaller set.
        $this->applyTraefikCertResources('traefik', $cluster);

        $this->laraKubeInfo('Removed '.count($unused).' certificate(s).');

        return $this->result(true, $report);
    }

    /**
     * Every host an Ingress or a Traefik IngressRoute on the local cluster
     * serves; null when the cluster could not be read.
     *
     * @return list<string>|null
     */
    private function servedHosts(Kubectl $cluster): ?array
    {
        $ingresses = $cluster->raw(['get', 'ingress', '-A', '-o', 'json']);

        if (! $ingresses->ok) {
            return null;
        }

        $hosts = [];
        foreach (json_decode($ingresses->output, true)['items'] ?? [] as $item) {
            foreach ($item['spec']['rules'] ?? [] as $rule) {
                if (is_string($rule['host'] ?? null)) {
                    $hosts[] = $rule['host'];
                }
            }
            foreach ($item['spec']['tls'] ?? [] as $tls) {
                foreach ($tls['hosts'] ?? [] as $host) {
                    $hosts[] = (string) $host;
                }
            }
        }

        // IngressRoutes (Traefik's own CRD) name hosts inside their match rules.
        $routes = $cluster->raw(['get', 'ingressroutes.traefik.io', '-A', '-o', 'json']);
        if ($routes->ok) {
            foreach (json_decode($routes->output, true)['items'] ?? [] as $item) {
                foreach ($item['spec']['routes'] ?? [] as $route) {
                    preg_match_all('/Host\(`([^`]+)`\)/', (string) ($route['match'] ?? ''), $matches);
                    array_push($hosts, ...$matches[1]);
                }
            }
        }

        return array_values(array_unique($hosts));
    }

    /**
     * @param  list<array{name: string, hosts: list<string>}>  $removed
     */
    private function result(bool $success, array $removed, ?string $error = null, bool $dryRun = false): int
    {
        if ($error !== null) {
            $this->laraKubeError($error);
        }

        if ($this->option('json')) {
            $this->jsonOutput(array_filter([
                'success' => $success,
                'removed' => $dryRun ? [] : $removed,
                'unused' => $removed,
                'dryRun' => $dryRun ?: null,
                'error' => $error,
            ], fn (mixed $value): bool => $value !== null));
        }

        return $success ? 0 : 1;
    }
}
