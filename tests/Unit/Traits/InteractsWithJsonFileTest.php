<?php

use App\Traits\InteractsWithJsonFile;
use Spatie\TemporaryDirectory\TemporaryDirectory;

function jsonFileHarness(): object
{
    return new class
    {
        use InteractsWithJsonFile;

        public function read(string $path): ?array
        {
            return self::readJsonFile($path);
        }
    };
}

test('readJsonFile returns null, not a crash, when the path is a directory', function (): void {
    // Confirmed live (2026-08-20): a stray directory literally named
    // .larakube.json crashed every caller of this trait with an uncaught
    // "file_get_contents(): ... Is a directory" error, because the old
    // file_exists() guard is also true for directories — unlike every other
    // bad-path case (missing, invalid JSON), which this already handles by
    // returning null.
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $dirShapedLikeAFile = $temporaryDirectory->path().'/looks-like-a-file.json';
    mkdir($dirShapedLikeAFile);

    expect(jsonFileHarness()->read($dirShapedLikeAFile))->toBeNull();

    $temporaryDirectory->delete();
});

test('readJsonFile returns null for a path that does not exist at all', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();

    expect(jsonFileHarness()->read($temporaryDirectory->path().'/nope.json'))->toBeNull();

    $temporaryDirectory->delete();
});

test('readJsonFile returns null for invalid JSON content', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $path = $temporaryDirectory->path().'/broken.json';
    file_put_contents($path, '{not valid json');

    expect(jsonFileHarness()->read($path))->toBeNull();

    $temporaryDirectory->delete();
});

test('readJsonFile decodes a real JSON file', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $path = $temporaryDirectory->path().'/real.json';
    file_put_contents($path, json_encode(['name' => 'demo', 'count' => 3]));

    expect(jsonFileHarness()->read($path))->toBe(['name' => 'demo', 'count' => 3]);

    $temporaryDirectory->delete();
});
