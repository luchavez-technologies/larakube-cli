<?php

namespace App\Services\Scaffolding;

use App\Enums\AppFramework;

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
        $fields = [$this->name()];

        return match ($framework) {
            AppFramework::LARAVEL => [...$fields, $this->email(), ...$this->laravelFields()],
            AppFramework::STATAMIC => [...$fields, $this->email(), ...$this->statamicFields()],
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
            default => $fields,
        };
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

            $fields[] = $field + $this->laravelRules($question['key']);
        }

        return $fields;
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
            'database' => ['defaultWhen' => [['when' => ['features' => 'ai'], 'value' => 'postgres']]],
            // FrankenPHP serves through Octane.
            'server' => ['implies' => ['frankenphp' => ['features' => 'octane']]],
            default => [],
        };
    }

    /**
     * Only what `statamic:new` takes as flags today.
     *
     * @return list<array<string, mixed>>
     */
    private function statamicFields(): array
    {
        return [
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
}
