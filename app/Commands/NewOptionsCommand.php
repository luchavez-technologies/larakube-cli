<?php

namespace App\Commands;

use App\Services\Scaffolding\LaravelQuestions;
use App\Traits\EmitsJsonOutput;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsCommandOptions;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

/**
 * Read-only catalog of the questions `larakube new` asks, and the flag that
 * answers each one headlessly. GUIs (LaraKube Desktop, LaraKube Cloud) build
 * their "new Laravel app" form from this rather than duplicating the enums.
 */
class NewOptionsCommand extends Command
{
    use EmitsJsonOutput, LaraKubeOutput, ReadsCommandOptions;

    protected $signature = 'new:options
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = 'List the questions `larakube new` asks and the flags that answer them';

    public function handle(): int
    {
        $questions = (new LaravelQuestions)->all();

        if ($this->flag('json')) {
            $this->jsonOutput(['success' => true, 'questions' => $questions]);

            return 0;
        }

        table(
            headers: ['Question', 'Flags', 'Default'],
            rows: array_map(fn (array $question): array => [
                $question['label'],
                implode(' ', array_column($question['options'], 'flag')),
                $question['default'] ?? '—',
            ], $questions),
        );

        return 0;
    }
}
