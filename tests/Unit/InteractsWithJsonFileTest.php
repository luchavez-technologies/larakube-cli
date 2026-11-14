<?php

use App\Traits\InteractsWithJsonFile;
use Spatie\TemporaryDirectory\TemporaryDirectory;

function jsonFileWriter(): object
{
    return new class
    {
        use InteractsWithJsonFile;

        public function write(string $path, array $data): void
        {
            self::atomicWriteJson($path, $data, 0600);
        }
    };
}

test('an atomic write through a symlink updates the target and keeps the link', function (): void {
    $dir = TemporaryDirectory::make()->deleteWhenDestroyed();
    $target = $dir->path().'/host-config.json';
    $link = $dir->path().'/config.json';
    file_put_contents($target, '{}');
    symlink($target, $link);

    jsonFileWriter()->write($link, ['email' => 'me@example.com']);

    expect(is_link($link))->toBeTrue()
        ->and(json_decode((string) file_get_contents($target), true))->toBe(['email' => 'me@example.com'])
        ->and(glob($dir->path().'/*.tmp*'))->toBeEmpty();
});

test('an atomic write to a plain file still replaces it in place', function (): void {
    $dir = TemporaryDirectory::make()->deleteWhenDestroyed();
    $path = $dir->path().'/config.json';
    file_put_contents($path, '{"old":true}');

    jsonFileWriter()->write($path, ['new' => true]);

    expect(json_decode((string) file_get_contents($path), true))->toBe(['new' => true]);
});
