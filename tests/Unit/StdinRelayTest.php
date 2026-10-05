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

    expect(iterator_to_array(StdinRelay::firstLine($stream), false))->toBe([]);
});
