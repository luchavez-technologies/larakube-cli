<?php

use App\Commands\WatchCommand;
use Spatie\TemporaryDirectory\TemporaryDirectory;

beforeEach(function (): void {
    $this->temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $this->tmp = $this->temporaryDirectory->path();
    mkdir($this->tmp.'/app', 0755, true);
    file_put_contents($this->tmp.'/app/User.php', '<?php');
    file_put_contents($this->tmp.'/.env', 'APP_ENV=local');
});

afterEach(function (): void {
    $this->temporaryDirectory->delete();
});

test('computeHash changes when a watched file mtime changes', function (): void {
    $before = WatchCommand::computeHash(['app', '.env'], $this->tmp);

    touch($this->tmp.'/app/User.php', time() + 100);

    $after = WatchCommand::computeHash(['app', '.env'], $this->tmp);

    expect($before)->not->toBe($after);
});

test('computeHash is stable when nothing changes', function (): void {
    $first = WatchCommand::computeHash(['app', '.env'], $this->tmp);
    $second = WatchCommand::computeHash(['app', '.env'], $this->tmp);

    expect($first)->toBe($second);
});

test('computeHash silently skips paths that do not exist', function (): void {
    $hash = WatchCommand::computeHash(['app', 'nonexistent-dir', '.env'], $this->tmp);

    expect($hash)->toBeString()->not->toBeEmpty();
});

test('computeHash recurses into subdirectories', function (): void {
    mkdir($this->tmp.'/app/Models', 0755, true);
    file_put_contents($this->tmp.'/app/Models/Post.php', '<?php');

    $before = WatchCommand::computeHash(['app'], $this->tmp);

    touch($this->tmp.'/app/Models/Post.php', time() + 100);

    $after = WatchCommand::computeHash(['app'], $this->tmp);

    expect($before)->not->toBe($after);

    @unlink($this->tmp.'/app/Models/Post.php');
    @rmdir($this->tmp.'/app/Models');
});

test('computeHash ignores bootstrap/cache to prevent reload loops', function (): void {
    mkdir($this->tmp.'/bootstrap/cache', 0755, true);
    file_put_contents($this->tmp.'/bootstrap/cache/services.php', '<?php');

    $before = WatchCommand::computeHash(['bootstrap'], $this->tmp);

    touch($this->tmp.'/bootstrap/cache/services.php', time() + 100);

    $after = WatchCommand::computeHash(['bootstrap'], $this->tmp);

    expect($before)->toBe($after);
});

test('computeHash ignores dotfiles and editor swap files', function (): void {
    $before = WatchCommand::computeHash(['app'], $this->tmp);

    file_put_contents($this->tmp.'/app/.DS_Store', 'junk');
    file_put_contents($this->tmp.'/app/User.php.swp', 'swap');
    file_put_contents($this->tmp.'/app/User.php~', 'backup');

    $after = WatchCommand::computeHash(['app'], $this->tmp);

    expect($before)->toBe($after);
});

test('snapshot keys files by their path relative to the project', function (): void {
    expect(array_keys(WatchCommand::snapshot(['app', '.env'], $this->tmp)))->toBe(['.env', 'app/User.php']);
});

test('changedFiles lists added, removed and modified files', function (): void {
    $before = ['app/A.php' => 1, 'app/B.php' => 1, 'app/C.php' => 1];
    $after = ['app/A.php' => 1, 'app/B.php' => 2, 'app/D.php' => 1];

    expect(WatchCommand::changedFiles($before, $after))->toBe(['app/B.php', 'app/C.php', 'app/D.php']);
});

test('describeChange names one file, or the first and a count', function (): void {
    expect(WatchCommand::describeChange(['app/A.php']))->toBe('app/A.php')
        ->and(WatchCommand::describeChange(['app/A.php', 'app/B.php', 'app/C.php']))->toBe('app/A.php and 2 more');
});

test('settle waits for a burst of saves to finish and reports every file in it', function (): void {
    $previous = ['app/A.php' => 1, 'app/B.php' => 1];
    $sequence = [
        ['app/A.php' => 2, 'app/B.php' => 1],
        ['app/A.php' => 2, 'app/B.php' => 2],
        ['app/A.php' => 2, 'app/B.php' => 2],
    ];
    $polls = 0;
    $snapshot = function () use (&$polls, $sequence): array {
        return $sequence[min($polls++, count($sequence) - 1)];
    };

    [$files, $settled] = WatchCommand::settle($snapshot, $previous, $snapshot(), 500_000, 1000);

    expect($files)->toBe(['app/A.php', 'app/B.php'])
        ->and($settled)->toBe(['app/A.php' => 2, 'app/B.php' => 2])
        ->and($polls)->toBeGreaterThan(3);
});

test('settle with no quiet period returns straight away', function (): void {
    $polls = 0;
    $snapshot = function () use (&$polls): array {
        $polls++;

        return ['app/A.php' => 2];
    };

    [$files] = WatchCommand::settle($snapshot, ['app/A.php' => 1], ['app/A.php' => 2], 500_000, 0);

    expect($files)->toBe(['app/A.php'])->and($polls)->toBe(0);
});
