<?php

namespace Tests\Support;

use App\Services\ToolRegistry;

/**
 * An in-memory tool registry for tests: every ToolRegistry::on() returns this
 * one (whatever cluster was asked for), rows are plain arrays, and each write
 * is recorded. Reset after every test by Tests\TestCase.
 */
final class FakeToolRegistry extends ToolRegistry
{
    /** @var list<array<string, mixed>> */
    public array $stored = [];

    /** @var list<list<array<string, mixed>>> every list written, in order */
    public array $writes = [];

    /** @param  list<array<string, mixed>>  $rows */
    public static function install(array $rows = []): self
    {
        $fake = new self('kubectl');
        $fake->stored = array_values($rows);
        ToolRegistry::resolveUsing(fn () => $fake);

        return $fake;
    }

    public static function uninstall(): void
    {
        ToolRegistry::resolveUsing(null);
    }

    protected function read(): array
    {
        return $this->stored;
    }

    protected function write(array $rows): bool
    {
        $this->writes[] = $rows;
        $this->stored = $rows;

        return true;
    }
}
