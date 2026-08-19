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
