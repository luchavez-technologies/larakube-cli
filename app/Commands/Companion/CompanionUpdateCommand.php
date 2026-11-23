<?php

namespace App\Commands\Companion;

use App\Data\ResourceRef;
use App\Enums\CompanionDriver;
use App\Exceptions\MissingFlagException;
use App\Services\Kubectl;
use App\Traits\EmitsJsonOutput;
use App\Traits\LaraKubeOutput;
use App\Traits\ManagesCompanions;
use LaravelZero\Framework\Commands\Command;

class CompanionUpdateCommand extends Command
{
    use EmitsJsonOutput, LaraKubeOutput, ManagesCompanions;

    protected $signature = 'companion:update
        {companion? : Companion slug to update (e.g. pgadmin)}
        {--all  : Update every installed companion whose image is not this build\'s}
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = 'Re-apply installed companions with the image this CLI build pins';

    public function handle(): int
    {
        if ($this->option('json')) {
            $this->enableJsonMode();
        }

        $this->renderHeader();

        $slug = $this->argument('companion');

        if ($slug !== null) {
            $companion = CompanionDriver::tryFrom((string) $slug);

            if ($companion === null) {
                return $this->result(false, [], [], "Unknown companion: {$slug}. Available: ".implode(', ', array_map(fn ($c) => $c->value, CompanionDriver::cases())).'.');
            }

            $targets = [$companion];
        } elseif ($this->option('all')) {
            $targets = CompanionDriver::cases();
        } elseif ($this->option('no-interaction') || $this->option('json')) {
            throw new MissingFlagException('companion', 'Which companion to update, or --all for every outdated one.');
        } else {
            $picked = $this->selectInstalledCompanion('update');

            if ($picked === null) {
                return $this->result(true, [], []);
            }

            $targets = [$picked];
        }

        $updated = [];
        $current = [];

        foreach ($targets as $companion) {
            $live = $this->liveCompanion($companion);

            if ($live === null) {
                if ($slug !== null) {
                    return $this->result(false, [], [], "{$companion->getLabel()} is not installed.");
                }

                continue;
            }

            if ($live['image'] === $companion->getImage()) {
                $current[] = $companion->value;
                $this->laraKubeLine("  <fg=gray>{$companion->getLabel()} already runs {$live['image']}.</>");

                continue;
            }

            $ok = $this->withSpin("Updating {$companion->getLabel()}: {$live['image']} → {$companion->getImage()}...", fn () => $this->deployCompanion($companion, $live['paused'] ? 0 : 1)->ok);

            if (! $ok) {
                return $this->result(false, $updated, $current, "Could not update {$companion->getLabel()}.");
            }

            if ($companion === CompanionDriver::PHPMYADMIN) {
                $this->reassertPhpMyAdminHosts();
            }

            $updated[] = $companion->value;
            $this->laraKubeInfo("{$companion->getLabel()} now runs {$companion->getImage()}".($live['paused'] ? ' (still paused: it starts on the new image when resumed)' : '').'.');
        }

        if ($updated === [] && $current !== []) {
            $this->laraKubeInfo('Every installed companion already runs this build\'s image.');
        }

        return $this->result(true, $updated, $current);
    }

    /**
     * The installed companion's image and whether it is paused; null when it
     * is not installed.
     *
     * @return array{image: string|null, paused: bool}|null
     */
    private function liveCompanion(CompanionDriver $companion): ?array
    {
        $deployment = Kubectl::current()->get(new ResourceRef('deployment', $companion->value, 'larakube-companions'));

        if (! is_array($deployment) || $deployment === []) {
            return null;
        }

        $image = $deployment['spec']['template']['spec']['containers'][0]['image'] ?? null;

        return [
            'image' => is_string($image) ? $image : null,
            'paused' => ($deployment['spec']['replicas'] ?? 1) === 0,
        ];
    }

    /**
     * phpMyAdmin's server list is set on the live Deployment from the
     * `phpmyadmin-hosts` ConfigMap; put it back after a re-apply so the
     * update never drops a project's database from the list.
     */
    private function reassertPhpMyAdminHosts(): void
    {
        $kubectl = Kubectl::current();
        $hosts = trim((string) ($kubectl->get(new ResourceRef('configmap', 'phpmyadmin-hosts', 'larakube-companions'))['data']['hosts'] ?? ''));

        if ($hosts !== '') {
            $kubectl->raw(['set', 'env', 'deployment/phpmyadmin', "PMA_HOSTS={$hosts}", '-n', 'larakube-companions']);
        }
    }

    /**
     * @param  list<string>  $updated
     * @param  list<string>  $current
     */
    private function result(bool $success, array $updated, array $current, ?string $error = null): int
    {
        if ($error !== null) {
            $this->laraKubeError($error);
        }

        if ($this->option('json')) {
            $this->jsonOutput(array_filter([
                'success' => $success,
                'updated' => $updated,
                'current' => $current,
                'error' => $error,
            ], fn (mixed $value): bool => $value !== null));
        }

        return $success ? 0 : 1;
    }
}
