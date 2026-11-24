<?php

namespace App\Commands\Templates;

use App\Enums\ClusterTool;
use App\Facades\State;
use App\Services\TemplateStore;
use App\Traits\EmitsJsonOutput;
use App\Traits\FailsWithJson;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsCommandOptions;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;
use RuntimeException;

/** Downloads, verifies and caches apps' templates without installing anything, so they work offline later. */
class TemplatesFetchCommand extends Command
{
    use EmitsJsonOutput, FailsWithJson, LaraKubeOutput, ReadsCommandOptions;

    protected $signature = 'templates:fetch
        {apps* : App slugs, e.g. openbao external-dns}
        {--engine= : The engine, for multi-engine apps}
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = 'Download and verify app templates from LaraKube Cloud for offline use';

    public function handle(TemplateStore $store): int
    {
        if ($this->flag('json')) {
            $this->enableJsonMode();
        } else {
            State::setJsonMode(false);
            State::setStdout(null);
        }

        $engine = $this->option('engine') ? (string) $this->option('engine') : null;
        $fetched = [];

        foreach ((array) $this->argument('apps') as $slug) {
            $tool = ClusterTool::tryFrom((string) $slug);

            if ($tool === null) {
                return $this->failed("Unknown app '{$slug}'.");
            }

            try {
                $entry = $store->download($tool, $engine);
            } catch (RuntimeException $exception) {
                return $this->failed("{$tool->value}: {$exception->getMessage()}");
            }

            $fetched[] = [
                'app' => $tool->value,
                'engine' => $engine ?? 'default',
                'version' => (string) ($entry['version'] ?? ''),
                'key_id' => (string) ($entry['key_id'] ?? ''),
            ];
        }

        $store->activate();

        if ($this->flag('json')) {
            $this->jsonOutput(['apps' => $fetched]);

            return self::SUCCESS;
        }

        table(
            headers: ['App', 'Engine', 'Version', 'Signed by'],
            rows: array_map(fn (array $row): array => [$row['app'], $row['engine'], $row['version'], $row['key_id']], $fetched),
        );
        $this->laraKubeInfo('Verified and cached in ~/.larakube/templates.');

        return self::SUCCESS;
    }
}
