<?php

namespace App\Data;

/**
 * One setting an app offers in a form, instead of as a raw environment
 * variable: what it's called, what it does, its type and default. It is
 * stored as a custom environment variable, so a saved value always overrides
 * the default.
 */
final readonly class ToolSetting
{
    public function __construct(
        public string $key,
        public string $label,
        public string $help,
        /** 'boolean' (stored as "true"/"false") or 'number'. */
        public string $type,
        public string $default,
        /** Shown after a number, e.g. "seconds". */
        public ?string $unit = null,
    ) {}

    /**
     * The setting as `tool:env --json` reports it, with its current value.
     *
     * @return array{key: string, label: string, help: string, type: string, default: string, unit: ?string, value: string, overridden: bool}
     */
    public function withValue(?string $saved): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'help' => $this->help,
            'type' => $this->type,
            'default' => $this->default,
            'unit' => $this->unit,
            'value' => $saved ?? $this->default,
            'overridden' => $saved !== null,
        ];
    }
}
