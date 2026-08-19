<?php

use App\Commands\DotenvAuditCommand;

/**
 * Pure-logic test for the source classifier — the only non-I/O piece of
 * `dotenv:audit`. Everything else in that command shells out to kubectl, which
 * CI doesn't have; see tests/Feature/DotenvAuditCommandTest.php for that, driven
 * through a faked shell_exec (namespace override, same trick as
 * ClusterContextTest.php).
 */
test('classifySource flags a key LaraKube generates as managed', function (): void {
    $command = new DotenvAuditCommand;

    expect($command->classifySource('DB_PASSWORD', ['DB_PASSWORD', 'MEILI_MASTER_KEY']))->toBe('LaraKube-managed');
});

test('classifySource flags anything else as custom — the actual rotation checklist', function (): void {
    $command = new DotenvAuditCommand;

    expect($command->classifySource('AIRTABLE_API_KEY', ['DB_PASSWORD', 'MEILI_MASTER_KEY']))->toBe('custom');
});

test('classifySource admits it cannot tell without a project config', function (): void {
    $command = new DotenvAuditCommand;

    expect($command->classifySource('ANYTHING', null))->toBe('unknown (no project context)');
});
