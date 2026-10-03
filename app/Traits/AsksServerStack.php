<?php

namespace App\Traits;

use App\Enums\AppFramework;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\SearchDriver;
use App\Enums\StorageDriver;
use App\Services\Scaffolding\ServerStack;

use function Laravel\Prompts\select;

/**
 * The database, cache, storage and search questions the server frameworks ask.
 * Each is answered by a flag (`--postgres`, `--redis`, `--minio` or
 * `--no-storage`, `--meilisearch`), else by `--fast`'s default, else asked.
 */
trait AsksServerStack
{
    use AnswersFromFlags;

    protected function configure(): void
    {
        parent::configure();

        $this->addAnswerFlags(DatabaseDriver::class, CacheDriver::class, StorageDriver::class, SearchDriver::class);
    }

    protected function askDatabase(AppFramework $framework, string $label = 'Which database engine would you like to use?'): DatabaseDriver
    {
        $allowed = ServerStack::databases($framework);

        return $this->flaggedCase(DatabaseDriver::class, $allowed) ?? DatabaseDriver::from($this->option('fast')
            ? $allowed[0]->value
            : select(label: $label, options: $this->labelled($allowed), default: $allowed[0]->value));
    }

    protected function askCache(AppFramework $framework): CacheDriver
    {
        $allowed = ServerStack::caches($framework);

        return $this->flaggedCase(CacheDriver::class, $allowed) ?? CacheDriver::from($this->option('fast')
            ? $allowed[0]->value
            : select(label: 'Which cache driver would you like to use?', options: $this->labelled($allowed), default: $allowed[0]->value));
    }

    protected function askStorage(AppFramework $framework, string $label = 'Which S3-compatible object storage would you like to use?'): ?StorageDriver
    {
        $allowed = ServerStack::storages();
        $optional = ServerStack::storageIsOptional($framework);
        $options = ($optional ? ['none' => 'None (local filesystem)'] : []) + $this->labelled($allowed);

        $value = $this->answeredStorage($allowed) ?? ($this->option('fast')
            ? $allowed[0]->value
            : select(label: $label, options: $options, default: $allowed[0]->value));

        return $value === 'none' && $optional ? null : StorageDriver::from($value);
    }

    protected function askSearch(AppFramework $framework, string $label = 'Which search driver would you like to use?'): ?SearchDriver
    {
        $allowed = ServerStack::searches($framework);

        $flagged = $this->flaggedCase(SearchDriver::class, $allowed);

        if ($flagged !== null) {
            return $flagged;
        }

        $value = $this->option('fast')
            ? 'none'
            : select(label: $label, options: ['none' => 'None'] + $this->labelled($allowed), default: 'none');

        return SearchDriver::tryFrom($value);
    }

    /**
     * @param  list<DatabaseDriver|CacheDriver|StorageDriver|SearchDriver>  $cases  the first is marked recommended
     * @return array<string, string>
     */
    private function labelled(array $cases): array
    {
        $options = [];

        foreach ($cases as $index => $case) {
            $options[$case->value] = $case->getLabel().($index === 0 && count($cases) > 1 && ! $case instanceof SearchDriver ? ' (Recommended)' : '');
        }

        return $options;
    }
}
