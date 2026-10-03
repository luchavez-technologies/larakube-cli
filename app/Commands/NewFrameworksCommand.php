<?php

namespace App\Commands;

use App\Services\Scaffolding\FrameworkCatalog;
use App\Traits\EmitsJsonOutput;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsCommandOptions;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

/**
 * Read-only catalog of the apps the CLI can scaffold, and the questions each one
 * asks. GUIs (LaraKube Desktop, LaraKube Cloud) build their "new app" form from
 * this instead of keeping their own list of frameworks and answers.
 */
class NewFrameworksCommand extends Command
{
    use EmitsJsonOutput, LaraKubeOutput, ReadsCommandOptions;

    protected $signature = 'new:frameworks
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = 'List the apps larakube can scaffold, and the questions each one asks';

    public function handle(): int
    {
        $frameworks = (new FrameworkCatalog)->all();

        if ($this->flag('json')) {
            $this->jsonOutput(['success' => true, 'categories' => (new FrameworkCatalog)->categories(), 'frameworks' => $frameworks]);

            return 0;
        }

        table(
            headers: ['Framework', 'Category', 'Command', 'Questions'],
            rows: array_map(fn (array $framework): array => [
                $framework['label'].($framework['hidden'] ? ' (hidden)' : ''),
                $framework['category'],
                $framework['command'],
                (string) count($framework['fields']),
            ], $frameworks),
        );

        return 0;
    }
}
