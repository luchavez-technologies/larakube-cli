<?php

namespace App\Services\Scaffolding;

use App\Contracts\PlexProvisionable;
use App\Enums\AppFramework;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\LaravelFeature;
use App\Enums\PhpVersion;
use App\Enums\SearchDriver;
use App\Enums\StorageDriver;
use BackedEnum;

/**
 * What a UI needs to offer "new app": every framework, and the fields it asks.
 *
 * A field is one question: `key`, `type` (text, select, multiselect, confirm),
 * `label`, `required`, and how its answer reaches the command: `arg` for the
 * positional name, `flag` on the field (`--email=` takes the value), or `flag`
 * on each option (`--react` is on or off). `visibleWhen`, `forcedWhen`,
 * `defaultWhen` and an option's `implies` carry the rules the wizard applies in code.
 * LaraKube Desktop and LaraKube Cloud render this instead of keeping their own copy.
 */
class FrameworkCatalog
{
    public const NAME_PATTERN = '^[a-z][a-z0-9]*(-[a-z0-9]+)*$';

    /**
     * The picker's categories, in order.
     *
     * @return list<array{id: string, label: string}>
     */
    public function categories(): array
    {
        return [
            ['id' => 'fullstack', 'label' => 'Full Stack'],
            ['id' => 'cms', 'label' => 'CMS'],
            ['id' => 'frontend', 'label' => 'Frontend'],
            ['id' => 'docs', 'label' => 'Docs'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return array_map(fn (AppFramework $framework): array => $this->describe($framework), AppFramework::cases());
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(AppFramework $framework): array
    {
        return [
            'slug' => $framework->value,
            'label' => $framework->getLabel(),
            'description' => $framework->description(),
            'category' => $framework->category(),
            'tech' => $framework->tech(),
            'logo' => $framework->logo(),
            'deployable' => $framework->isDeployable(),
            'hidden' => $framework->isHidden(),
            'comingSoon' => $framework->comingSoon(),
            'command' => $framework->scaffoldCommand(),
            'args' => $framework->scaffoldArgs(),
            // `init` on an existing project asks for the Let's Encrypt email for these.
            'initEmail' => in_array($framework, [AppFramework::LARAVEL, AppFramework::STATAMIC, AppFramework::WORDPRESS], true),
            'fields' => $this->fields($framework),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fields(AppFramework $framework): array
    {
        $fields = array_map(fn (array $field): array => ($framework->joinsCommons() ? $this->withCommons($field) : $field) + ['group' => $this->group($framework, $field['key'])], $this->rawFields($framework));

        return $framework->joinsCommons() ? [...$fields, $this->commonsOptOut()] : $fields;
    }

    /**
     * Marks each option that would be a shared Commons service, so a form can say
     * what an app will share without knowing which drivers the Commons offers.
     *
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    private function withCommons(array $field): array
    {
        $enum = match ($field['key']) {
            'database' => DatabaseDriver::class,
            'cache' => CacheDriver::class,
            'search' => SearchDriver::class,
            'storage' => StorageDriver::class,
            default => null,
        };

        if ($enum === null || ! isset($field['options'])) {
            return $field;
        }

        $field['options'] = array_map(function (array $option) use ($enum): array {
            $case = $enum::tryFrom($option['value']);
            $service = $case instanceof PlexProvisionable && $case->isPlexReady() ? $case->commonsServiceName() : null;

            return $service === null ? $option : $option + ['commons' => $service];
        }, $field['options']);

        return $field;
    }

    /**
     * Joining the Commons is the default; this is how an app opts out and runs its own copies.
     *
     * @return array<string, mixed>
     */
    private function commonsOptOut(): array
    {
        return [
            'key' => 'selfContained',
            'type' => 'confirm',
            'role' => 'commons-opt-out',
            'label' => 'Keep this app self-contained',
            'description' => 'By default the app shares your computer\'s Commons (database, cache and storage) with your other apps. Tick this to give it its own copies instead.',
            'required' => false,
            'default' => false,
            'flag' => '--no-plex',
            'group' => 'essential',
        ];
    }

    /**
     * What is asked up front, and what sits behind "Advanced".
     */
    private function group(AppFramework $framework, string $key): string
    {
        $essential = match ($framework) {
            AppFramework::LARAVEL => ['name', 'email', 'frontend', 'database'],
            AppFramework::STATAMIC => ['name', 'email', 'database'],
            default => ['name', 'email', 'template', 'database', 'typescript'],
        };

        return in_array($key, $essential, true) ? 'essential' : 'advanced';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rawFields(AppFramework $framework): array
    {
        $fields = [$this->name()];

        return match ($framework) {
            AppFramework::LARAVEL => [...$fields, $this->email(), ...$this->laravelFields()],
            AppFramework::STATAMIC => [...$fields, $this->email(), ...$this->statamicFields()],
            AppFramework::NEXTJS => [...$fields, ...$this->nextjsFields()],
            AppFramework::VITE => [...$fields, $this->template('Template', 'react-ts', [
                'react-ts' => 'React',
                'vue-ts' => 'Vue',
                'svelte-ts' => 'Svelte',
                'solid-ts' => 'Solid',
                'vanilla-ts' => 'Plain TypeScript',
            ])],
            AppFramework::ASTRO => [...$fields, $this->template('Template', 'minimal', [
                'minimal' => 'Minimal',
                'basics' => 'Basics',
                'blog' => 'Blog',
            ])],
            AppFramework::DOCUSAURUS => [...$fields, $this->template('Template', 'classic', ['classic' => 'Classic']), [
                'key' => 'typescript',
                'type' => 'confirm',
                'label' => 'Use TypeScript',
                'required' => false,
                'default' => true,
                'flag' => '--typescript',
            ]],
            default => ServerStack::covers($framework) ? [...$fields, ...$this->serverStackFields($framework)] : $fields,
        };
    }

    /**
     * The questions every server framework asks, from the same lists its wizard uses.
     *
     * @return list<array<string, mixed>>
     */
    private function serverStackFields(AppFramework $framework): array
    {
        $databases = ServerStack::databases($framework);
        $caches = ServerStack::caches($framework);

        $fields = [
            $this->select('database', 'Database', $this->options($databases, recommended: $databases[0]), $databases[0]->value),
            $this->select('cache', 'Cache', $this->options($caches, recommended: $caches[0]), $caches[0]->value),
            $this->storage(ServerStack::storages(), ServerStack::storageIsOptional($framework)),
            $this->search(ServerStack::searches($framework)),
        ];

        return $framework === AppFramework::WORDPRESS
            ? [$this->select('php', 'PHP version', $this->options(array_values(array_filter(PhpVersion::cases(), fn (PhpVersion $v): bool => (float) $v->value >= 8.2)), recommended: PhpVersion::PHP_8_4), PhpVersion::PHP_8_4->value), ...$fields]
            : $fields;
    }

    /** @return array<string, mixed> */
    private function name(): array
    {
        return [
            'key' => 'name',
            'type' => 'text',
            'label' => 'App name',
            'description' => 'Lowercase letters, numbers and dashes.',
            'required' => true,
            'arg' => 'positional',
            'pattern' => self::NAME_PATTERN,
            'maxLength' => 50,
            'reserved' => ['console'],
            'placeholder' => 'my-first-app',
        ];
    }

    /** @return array<string, mixed> */
    private function email(): array
    {
        return [
            'key' => 'email',
            'type' => 'text',
            'label' => 'Your email',
            'description' => "For your site's SSL certificate.",
            'required' => true,
            'flag' => '--email=',
            'format' => 'email',
            'placeholder' => 'you@example.com',
        ];
    }

    /**
     * @param  array<string, string>  $options  value => label
     * @return array<string, mixed>
     */
    private function template(string $label, string $default, array $options): array
    {
        return [
            'key' => 'template',
            'type' => 'select',
            'label' => $label,
            'required' => true,
            'default' => $default,
            'flag' => '--template=',
            'options' => array_map(fn (string $value, string $name): array => ['value' => $value, 'label' => $name], array_keys($options), $options),
        ];
    }

    /**
     * Laravel's wizard questions, with the rules it applies in code made explicit.
     *
     * @return list<array<string, mixed>>
     */
    private function laravelFields(): array
    {
        $fields = [];

        foreach ((new LaravelQuestions)->all() as $question) {
            $field = [
                'key' => $question['key'],
                'type' => $question['multiple'] ? 'multiselect' : 'select',
                'label' => $question['label'],
                'required' => ! $question['nullable'] && ! $question['multiple'],
                'multiple' => $question['multiple'],
                'nullable' => $question['nullable'],
                'default' => $question['default'],
                'options' => $question['options'],
            ];

            foreach (['conflicts', 'requiresFeature'] as $extra) {
                if (isset($question[$extra])) {
                    $field[$extra] = $question[$extra];
                }
            }

            $fields[] = $field + $this->laravelRules($question['key']) + $this->laravelSuggestion($question['key']);
        }

        return $fields;
    }

    /**
     * What a GUI for newcomers should preselect, where it differs from the CLI's
     * `--fast` default: React, PostgreSQL (it joins the shared Commons) and FPM/Nginx.
     *
     * @return array<string, string>
     */
    private function laravelSuggestion(string $key): array
    {
        return match ($key) {
            'frontend' => ['suggested' => 'react'],
            'database' => ['suggested' => 'postgres'],
            'server' => ['suggested' => 'fpm-nginx'],
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function laravelRules(string $key): array
    {
        return match ($key) {
            // Scout's search driver is only asked when Scout is on.
            'search' => ['visibleWhen' => ['features' => 'scout']],
            // Horizon needs Redis, so the cache question isn't asked.
            'cache' => ['forcedWhen' => [['when' => ['features' => 'horizon'], 'value' => 'redis']]],
            // An AI app wants pgvector, so Postgres.
            'database' => [
                'defaultWhen' => [['when' => ['features' => 'ai'], 'value' => 'postgres']],
                'optionHints' => ['postgres' => 'PostgreSQL joins the shared Plex Commons database when one is running, instead of starting its own.'],
            ],
            // FrankenPHP serves through Octane.
            'server' => ['implies' => ['frankenphp' => ['features' => 'octane']]],
            default => [],
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function statamicFields(): array
    {
        $php = array_values(array_filter(PhpVersion::cases(), fn (PhpVersion $v): bool => (float) $v->value >= 8.2));

        return [
            $this->select('php', 'PHP version', $this->options($php), default: PhpVersion::PHP_8_5->value),
            $this->select('features', 'Laravel features', $this->options(LaravelFeature::cases()), multiple: true) + [
                'conflicts' => [[LaravelFeature::HORIZON->value, LaravelFeature::QUEUES->value]],
            ],
            $this->select('database', 'Database', $this->options([DatabaseDriver::MYSQL, DatabaseDriver::MARIADB, DatabaseDriver::POSTGRESQL]), default: DatabaseDriver::MYSQL->value) + [
                'defaultWhen' => [['when' => ['features' => 'ai'], 'value' => 'postgres']],
            ],
            $this->select('cache', 'Cache', $this->options(CacheDriver::cases()), default: CacheDriver::REDIS->value) + [
                'forcedWhen' => [['when' => ['features' => 'horizon'], 'value' => 'redis']],
            ],
            $this->storage(),
            $this->search(),
            [
                'key' => 'content',
                'type' => 'select',
                'label' => 'Where content lives',
                'description' => 'In the database, each environment has its own. In files, content is committed with the site.',
                'required' => true,
                'default' => 'database',
                'flag' => '--content=',
                'options' => [
                    ['value' => 'database', 'label' => 'Database'],
                    ['value' => 'files', 'label' => 'Files'],
                ],
            ],
            [
                'key' => 'starterKit',
                'type' => 'text',
                'label' => 'Starter kit',
                'description' => 'As vendor/kit. Leave empty for a blank site.',
                'required' => false,
                'flag' => '--starter-kit=',
            ],
        ];
    }

    /**
     * Next.js asks for a database, storage and search; its cache is always Redis.
     *
     * @return list<array<string, mixed>>
     */
    private function nextjsFields(): array
    {
        return [
            $this->select('database', 'Database', $this->options([DatabaseDriver::POSTGRESQL, DatabaseDriver::MYSQL, DatabaseDriver::MARIADB], recommended: DatabaseDriver::POSTGRESQL), default: DatabaseDriver::POSTGRESQL->value),
            $this->storage([StorageDriver::MINIO, StorageDriver::SEAWEEDFS, StorageDriver::GARAGE]),
            $this->search([SearchDriver::MEILISEARCH, SearchDriver::TYPESENSE]),
        ];
    }

    /**
     * @param  list<BackedEnum>  $cases
     * @return list<array<string, mixed>>
     */
    private function options(array $cases, ?BackedEnum $recommended = null): array
    {
        return array_map(fn (BackedEnum $case): array => [
            'value' => (string) $case->value,
            'label' => (string) $case->getLabel(),
            'flag' => "--{$case->value}",
            'recommended' => $recommended === $case,
        ], $cases);
    }

    /**
     * @param  list<array<string, mixed>>  $options
     * @return array<string, mixed>
     */
    private function select(string $key, string $label, array $options, ?string $default = null, bool $multiple = false): array
    {
        return [
            'key' => $key,
            'type' => $multiple ? 'multiselect' : 'select',
            'label' => $label,
            'required' => ! $multiple,
            'multiple' => $multiple,
            'nullable' => false,
            'default' => $default,
            'options' => $options,
        ];
    }

    /**
     * Object storage: a provider, or none (`--no-storage`).
     *
     * @param  list<StorageDriver>|null  $only
     * @return array<string, mixed>
     */
    private function storage(?array $only = null, bool $optional = true): array
    {
        $field = $this->select('storage', 'Object storage', [
            ...($optional ? [['value' => 'none', 'label' => 'None', 'flag' => '--no-storage', 'recommended' => false]] : []),
            ...$this->options($only ?? StorageDriver::cases(), recommended: StorageDriver::MINIO),
        ], default: StorageDriver::MINIO->value);

        return $field;
    }

    /**
     * Search: none (no flag), or a driver.
     *
     * @param  list<SearchDriver>|null  $only
     * @return array<string, mixed>
     */
    private function search(?array $only = null): array
    {
        return $this->select('search', 'Search', [
            ['value' => 'none', 'label' => 'None', 'flag' => null, 'recommended' => false],
            ...$this->options($only ?? SearchDriver::cases()),
        ], default: 'none');
    }
}
