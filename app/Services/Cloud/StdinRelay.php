<?php

namespace App\Services\Cloud;

use Generator;

/**
 * Hands one line of this process's own standard input to a child that is waiting for it, such as the verification
 * code `gcloud auth login --no-launch-browser` asks for. The generator is the child's input: it yields nothing while no
 * line has arrived, then the line, then ends, which closes the child's stdin.
 */
final class StdinRelay
{
    /**
     * @param  resource  $stream
     * @return Generator<int, string>
     */
    public static function firstLine($stream): Generator
    {
        stream_set_blocking($stream, false);

        $buffer = '';

        while (true) {
            $chunk = fgets($stream);

            if ($chunk === false) {
                if (feof($stream)) {
                    return;
                }

                usleep(50_000);

                yield '';

                continue;
            }

            $buffer .= $chunk;

            if (str_ends_with($buffer, "\n")) {
                yield $buffer;

                return;
            }
        }
    }

    /**
     * Poll a file for a single line without touching STDIN (safe on Windows pipes where stream_set_blocking is a no-op).
     *
     * @return Generator<int, string>
     */
    public static function fromFile(string $path, int $pollMicroseconds = 50_000): Generator
    {
        while (true) {
            clearstatcache(true, $path);

            if (is_file($path)) {
                $contents = @file_get_contents($path);

                if ($contents !== false && $contents !== '') {
                    @unlink($path);
                    $first = strstr($contents, "\n", true);
                    $line = rtrim($first === false ? $contents : $first, "\r");

                    yield $line."\n";

                    return;
                }
            }

            usleep($pollMicroseconds);

            yield '';
        }
    }
}
