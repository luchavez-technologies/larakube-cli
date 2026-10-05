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
}
