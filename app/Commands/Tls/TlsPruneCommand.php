<?php

namespace App\Commands\Tls;

use App\Services\Kubectl;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\LaraKubeOutput;
use App\Traits\ProvisionsK3sNode;
use App\Traits\RequiresFlagsWhenNonInteractive;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;
use stdClass;

/**
 * Remove certificates no ingress uses from Traefik's acme.json.
 *
 * Traefik keeps and renews every certificate it ever issued, so each removed
 * tool leaves one behind, and it has no API to delete them. The file also
 * holds every private key: it is read and written over `kubectl exec` only,
 * never touches the local disk, and is backed up in place first.
 */
class TlsPruneCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, LaraKubeOutput, ProvisionsK3sNode, RequiresFlagsWhenNonInteractive;

    private const ACME_FILE = '/acme/acme.json';

    protected $signature = 'tls:prune
        {environment : The cloud environment whose unused certificates to remove}
        {--context=  : Target a specific kube-context}
        {--force     : Skip the confirmation prompt}';

    protected $description = 'Remove stored Let\'s Encrypt certificates that no ingress uses anymore';

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');

        if ($env === 'local') {
            $this->laraKubeError('Local clusters use the LaraKube Local CA, not Let\'s Encrypt.');

            return 1;
        }

        $context = $this->resolveToolContext($env, (string) $this->option('context') ?: null);
        if ($context === null || $context === '') {
            $this->laraKubeError("No kube-context resolved for '{$env}'. Pass --context=.");

            return 1;
        }

        $kubectl = Kubectl::forContext($context)->prefix();

        if (! $this->traefikInstalledOnContext($context)) {
            $this->laraKubeError("Traefik isn't installed on this cluster.");

            return 1;
        }

        if ($this->traefikIsManaged($kubectl)) {
            $this->laraKubeError('tls:prune supports single-node (VPS) clusters for now. This is a managed cluster.');

            return 1;
        }

        $read = Process::run("{$kubectl} exec -n traefik deploy/traefik -- cat ".self::ACME_FILE);
        // Decoded as objects: Traefik's own `{}` values must not come back as `[]`.
        $acme = $read->successful() ? json_decode($read->output()) : null;

        if (! $acme instanceof stdClass) {
            $this->laraKubeError("Could not read Traefik's acme.json. Nothing was changed.");

            return 1;
        }

        $routed = [];
        foreach ($this->clusterIngresses($kubectl) as $ingress) {
            array_push($routed, ...$ingress['hosts']);
        }

        $pruned = $this->pruneUnusedCertificates($acme, array_map('strtolower', $routed));

        if ($pruned === []) {
            $this->laraKubeInfo('Every stored certificate is still used by an ingress. Nothing to prune.');

            return 0;
        }

        $this->line('  <fg=gray>Stored certificates no ingress uses:</>');
        foreach ($pruned as $domain) {
            $this->line("  <fg=red>•</> {$domain}");
        }

        if (! $this->confirmDestructive([
            count($pruned)." certificate(s) will be removed from Traefik's acme.json on '{$env}'.",
            'A backup is kept next to it as acme.json.bak. Hosts still routed are never touched.',
            'Traefik restarts: expect a few seconds of errors on every site.',
        ])) {
            return 0;
        }

        if (! Process::run("{$kubectl} exec -n traefik deploy/traefik -- cp -p ".self::ACME_FILE.' '.self::ACME_FILE.'.bak')->successful()) {
            $this->laraKubeError('Could not back up acme.json. Nothing was changed.');

            return 1;
        }

        // Written beside the original, then swapped in; Traefik requires 0600.
        $write = Process::input((string) json_encode($acme, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))->run(
            "{$kubectl} exec -i -n traefik deploy/traefik -- sh -c "
            .escapeshellarg('cat > '.self::ACME_FILE.'.new && chmod 600 '.self::ACME_FILE.'.new && mv '.self::ACME_FILE.'.new '.self::ACME_FILE),
        );

        if (! $write->successful()) {
            $this->laraKubeError('Could not write the pruned acme.json. The original is untouched.');

            return 1;
        }

        // Restart right away: Traefik holds the certificates in memory and would
        // write the pruned ones back the next time it saves the file.
        $restarted = Process::run("{$kubectl} rollout restart deployment/traefik -n traefik")->successful()
            && Process::timeout(190)->run("{$kubectl} rollout status deployment/traefik -n traefik --timeout=180s")->successful();

        if (! $restarted) {
            $this->laraKubeError('acme.json was pruned, but Traefik did not restart cleanly. See the output above.');
            $this->line('  <fg=gray>The backup is at</> '.self::ACME_FILE.'.bak <fg=gray>inside the Traefik pod.</>');

            return 1;
        }

        $left = array_intersect($pruned, $this->storedCertificateDomains($kubectl));
        if ($left !== []) {
            $this->laraKubeError('Traefik still has: '.implode(', ', $left).'. It may have saved them again before restarting; run tls:prune again.');

            return 1;
        }

        $this->laraKubeInfo('✅ Removed '.count($pruned)." unused certificate(s) from '{$env}'.");

        return 0;
    }

    /**
     * Drop every certificate whose domains are all unrouted, across every
     * resolver in the file. Returns the pruned main domains.
     *
     * @param  list<string>  $routed  lowercase hosts
     * @return list<string>
     */
    protected function pruneUnusedCertificates(stdClass $acme, array $routed): array
    {
        $pruned = [];

        foreach (get_object_vars($acme) as $resolver) {
            if (! $resolver instanceof stdClass || ! is_array($resolver->Certificates ?? null)) {
                continue;
            }

            $kept = [];
            foreach ($resolver->Certificates as $certificate) {
                $domains = array_map('strtolower', array_filter([
                    $certificate->domain->main ?? null,
                    ...($certificate->domain->sans ?? []),
                ]));

                if ($domains !== [] && array_intersect($domains, $routed) === []) {
                    $pruned[] = (string) $certificate->domain->main;

                    continue;
                }

                $kept[] = $certificate;
            }

            $resolver->Certificates = $kept;
        }

        sort($pruned);

        return $pruned;
    }
}
