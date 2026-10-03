<?php

namespace App\Services\Scaffolding;

use App\Contracts\HasHiddenComponents;
use App\Data\ConfigData;
use App\Enums\Blueprint;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\FrontendStack;
use App\Enums\LaravelFeature;
use App\Enums\OperatingSystem;
use App\Enums\PackageManager;
use App\Enums\PhpVersion;
use App\Enums\SearchDriver;
use App\Enums\ServerVariation;
use App\Enums\StorageDriver;
use BackedEnum;

/**
 * The questions `larakube new` asks, and the flag that answers each one
 * headlessly. Both `new:options` and `new:frameworks` read it, so the two can't
 * disagree about what a Laravel app asks.
 */
class LaravelQuestions
{
    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return [
            $this->describeQuestion('blueprints', 'Specialized blueprints', Blueprint::class, multiple: true, except: [Blueprint::LARAVEL]),
            $this->describeQuestion('server', 'Server variation', ServerVariation::class, default: ServerVariation::FRANKENPHP),
            $this->describeQuestion('php', 'PHP version', PhpVersion::class, default: PhpVersion::PHP_8_5),
            $this->describeQuestion('os', 'Operating system', OperatingSystem::class, default: OperatingSystem::ALPINE),
            $this->describeQuestion('frontend', 'Frontend stack', FrontendStack::class, nullable: true),
            $this->describeQuestion('features', 'Laravel features', LaravelFeature::class, multiple: true) + [
                'conflicts' => [[LaravelFeature::HORIZON->value, LaravelFeature::QUEUES->value]],
            ],
            $this->describeQuestion('search', 'Search driver for Scout', SearchDriver::class, default: SearchDriver::MEILISEARCH) + [
                'requiresFeature' => LaravelFeature::SCOUT->value,
            ],
            $this->describeQuestion('database', 'Database', DatabaseDriver::class, default: DatabaseDriver::MYSQL),
            $this->describeQuestion('cache', 'Cache', CacheDriver::class, default: CacheDriver::REDIS),
            $this->describeQuestion('storage', 'Object storage', StorageDriver::class, nullable: true),
            $this->describeQuestion('packageManager', 'JavaScript package manager', PackageManager::class, default: PackageManager::NPM),
        ];
    }

    /**
     * Defaults are the ones `new --fast` fills in. `unavailableWith` lists the
     * server variations under which an option is hidden (FrankenPHP rules out
     * SQLite, and implies Octane), so a form can mirror the wizard's filtering.
     *
     * @param  class-string<BackedEnum>  $enum
     * @param  list<BackedEnum>  $except
     * @return array{key: string, label: string, multiple: bool, nullable: bool, default: string|null, options: list<array{value: string, label: string, flag: string, unavailableWith: list<string>}>}
     */
    private function describeQuestion(string $key, string $label, string $enum, bool $multiple = false, bool $nullable = false, ?BackedEnum $default = null, array $except = []): array
    {
        $options = [];

        foreach ($enum::cases() as $case) {
            if (in_array($case, $except, true)) {
                continue;
            }

            $unavailableWith = $this->unavailableWith($case);

            // Hidden under every server variation (PHP < 8.3 for a new app).
            if (count($unavailableWith) === count(ServerVariation::cases())) {
                continue;
            }

            $options[] = [
                'value' => (string) $case->value,
                'label' => (string) $case->getLabel(),
                'flag' => "--{$case->value}",
                'unavailableWith' => $unavailableWith,
            ];
        }

        return [
            'key' => $key,
            'label' => $label,
            'multiple' => $multiple,
            'nullable' => $nullable,
            'default' => $default === null ? null : (string) $default->value,
            'options' => $options,
        ];
    }

    /**
     * @return list<string>
     */
    private function unavailableWith(BackedEnum $case): array
    {
        if (! $case instanceof HasHiddenComponents) {
            return [];
        }

        $servers = [];

        foreach (ServerVariation::cases() as $server) {
            $config = (new ConfigData)->setIsScaffolding(true)->setServerVariation($server);

            if ($case->isHidden($config)) {
                $servers[] = $server->value;
            }
        }

        return $servers;
    }
}
