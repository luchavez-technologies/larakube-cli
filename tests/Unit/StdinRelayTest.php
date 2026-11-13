<?php

use App\Services\Cloud\StdinRelay;

test('only the first line that arrives is handed to the waiting child, and then its input ends', function (): void {
    $stream = fopen('php://memory', 'w+');
    fwrite($stream, "4/0AbCdEf\nsecond line\n");
    rewind($stream);

    expect(iterator_to_array(StdinRelay::firstLine($stream), false))->toBe(["4/0AbCdEf\n"]);
});

test('a stream that ends without a line hands the child nothing', function (): void {
    $stream = fopen('php://memory', 'w+');

    expect(iterator_to_array(StdinRelay::firstLine($stream), false))->toBeEmpty();
});

test('fromFile polls without blocking until the code file exists, then yields the first line and unlinks it', function (): void {
    $path = sys_get_temp_dir().'/stdin-relay-'.bin2hex(random_bytes(6)).'.txt';
    @unlink($path);

    $generator = StdinRelay::fromFile($path, 1_000);

    expect($generator->current())->toBeEmpty();
    $generator->next();
    expect($generator->valid())->toBeTrue()
        ->and($generator->current())->toBeEmpty();

    file_put_contents($path, "4/0AbCdEf\r\nsecond line\r\n");

    $generator->next();
    expect($generator->valid())->toBeTrue()
        ->and($generator->current())->toBe("4/0AbCdEf\n")
        ->and($path)->not->toBeFile();

    $generator->next();
    expect($generator->valid())->toBeFalse();
});
