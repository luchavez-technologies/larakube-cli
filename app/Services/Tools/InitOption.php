<?php

namespace App\Services\Tools;

/**
 * One option of a Cluster Tool's init command: what it is called, whether it
 * is an on/off flag, a value or a repeatable value, and how it reads to a
 * person. The spec (ToolInitSpec) lists them per tool; command signatures and
 * the forms Desktop draws both come from here, so they cannot drift apart.
 */
final readonly class InitOption
{
    public const FLAG = 'flag';

    public const VALUE = 'value';

    public const LIST = 'list';

    /** Options that are the command's own mechanics, not a question for a person. */
    private const MECHANICS = ['context', 'force'];

    private function __construct(
        public string $name,
        public string $kind,
        public string $description,
        public string|bool|null $default = null,
    ) {}

    public static function flag(string $name, string $description): self
    {
        return new self($name, self::FLAG, $description, false);
    }

    public static function value(string $name, string $description, ?string $default = null): self
    {
        return new self($name, self::VALUE, $description, $default);
    }

    public static function list(string $name, string $description): self
    {
        return new self($name, self::LIST, $description);
    }

    public static function context(): self
    {
        return self::value('context', 'Target a specific kube-context');
    }

    public static function force(): self
    {
        return self::flag('force', 'Skip the confirmation prompt');
    }

    public static function domain(string $description): self
    {
        return self::value('domain', $description);
    }

    public static function vpnOnly(string $description = 'Restrict access via NetBird VPN IP whitelisting'): self
    {
        return self::flag('vpn-only', $description);
    }

    /** Route the host through Cloudflare. Some tools default to it and take `--proxied=0` to opt out. */
    public static function proxied(bool $defaultOn = false): self
    {
        return $defaultOn
            ? self::value('proxied', 'Route the host through Cloudflare Proxy (orange cloud); pass --proxied=0 for DNS-only', '1')
            : self::flag('proxied', 'Route the host through Cloudflare Proxy (orange cloud) instead of DNS-only');
    }

    /** The Laravel signature fragment, e.g. `{--domain= : Base domain}`. */
    public function signature(): string
    {
        $suffix = match ($this->kind) {
            self::LIST => '=*',
            self::VALUE => '='.($this->default ?? ''),
            default => '',
        };

        return '{--'.$this->name.$suffix.' : '.$this->description.'}';
    }

    public function isMechanics(): bool
    {
        return in_array($this->name, self::MECHANICS, true);
    }

    /**
     * The field a form draws for this option, in the vocabulary `new:frameworks`
     * uses: an on/off flag is a `confirm`, anything else is `text`.
     *
     * @return array<string, mixed>
     */
    public function field(): array
    {
        $field = [
            'key' => lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $this->name)))),
            'type' => $this->kind === self::FLAG ? 'confirm' : 'text',
            'role' => $this->role(),
            'label' => $this->label(),
            'description' => $this->description,
            'required' => false,
            'flag' => $this->kind === self::FLAG ? "--{$this->name}" : "--{$this->name}=",
        ];

        if ($this->kind === self::LIST) {
            $field['multiple'] = true;
        }

        if ($this->default !== null && $this->default !== '') {
            $field['default'] = $this->default;
        }

        return $field;
    }

    /**
     * Where a form puts it: `host` and `account` have their own controls,
     * `access` is who can reach the tool, and every `option` is a plain choice.
     */
    private function role(): string
    {
        return match ($this->name) {
            'domain', 'alias' => 'host',
            'admin-email' => 'account',
            'vpn-only', 'proxied' => 'access',
            default => 'option',
        };
    }

    private function label(): string
    {
        $label = ucfirst(str_replace('-', ' ', $this->name));

        return str_replace(['Sso', 'Vpn', 'Url', 'Plex', 'Cloudflare', 'Dns'], ['SSO', 'VPN', 'URL', 'Plex', 'Cloudflare', 'DNS'], $label);
    }
}
