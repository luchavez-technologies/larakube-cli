<?php

namespace App\Traits;

use App\Enums\StorageDriver;
use BackedEnum;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputOption;

/**
 * Lets a scaffolding command's wizard questions be answered by flags, the way
 * `larakube new` already does (`--postgres`, `--redis`, `--minio`, `--8.4`).
 * Each answer is one on/off flag per enum case, so a headless run (LaraKube
 * Desktop, CI) never has to prompt. A question with no flag is asked as before.
 */
trait AnswersFromFlags
{
    /**
     * Register one flag per case of each enum. A name another enum already took is skipped.
     *
     * @param  class-string<BackedEnum>  ...$enums
     */
    protected function addAnswerFlags(string ...$enums): void
    {
        try {
            $this->addOption('no-storage', null, InputOption::VALUE_NONE, 'No object storage');
        } catch (InvalidArgumentException) {
            // Already registered.
        }

        foreach ($enums as $enum) {
            foreach ($enum::cases() as $case) {
                try {
                    $this->addOption((string) $case->value, null, InputOption::VALUE_NONE, method_exists($case, 'getLabel') ? (string) $case->getLabel() : (string) $case->value);
                } catch (InvalidArgumentException) {
                    // Already registered by an earlier enum.
                }
            }
        }
    }

    /**
     * The first case of $enum whose flag was given, limited to $allowed when set.
     *
     * @template T of BackedEnum
     *
     * @param  class-string<T>  $enum
     * @param  list<T>|null  $allowed
     * @return T|null
     */
    protected function flaggedCase(string $enum, ?array $allowed = null): ?BackedEnum
    {
        return $this->flaggedCases($enum, $allowed)[0] ?? null;
    }

    /**
     * Every case of $enum whose flag was given.
     *
     * @template T of BackedEnum
     *
     * @param  class-string<T>  $enum
     * @param  list<T>|null  $allowed
     * @return list<T>
     */
    protected function flaggedCases(string $enum, ?array $allowed = null): array
    {
        $given = [];

        foreach ($allowed ?? $enum::cases() as $case) {
            if ($this->input->hasOption((string) $case->value) && $this->input->getOption((string) $case->value) === true) {
                $given[] = $case;
            }
        }

        return $given;
    }

    /**
     * The object storage answered by a flag: one of $allowed, or `none` for
     * `--no-storage`. Null when no flag was given, so the wizard asks.
     *
     * @param  list<StorageDriver>  $allowed
     */
    protected function answeredStorage(array $allowed): ?string
    {
        if ($this->input->hasOption('no-storage') && $this->input->getOption('no-storage') === true) {
            return 'none';
        }

        return $this->flaggedCase(StorageDriver::class, $allowed)?->value;
    }
}
