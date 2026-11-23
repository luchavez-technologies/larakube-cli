<?php

namespace App\Traits;

use App\Facades\State;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Pulls a builder image with its progress shown and a limit on how long it may
 * take. A pull that ran silently with no limit looked exactly like a frozen run
 * when the container runtime's network was slow.
 *
 * Needs ResolvesContainerRuntime (for pullImageCommand()) on the using class.
 */
trait PullsContainerImages
{
    /** No output for this long means the pull has stalled. */
    protected const PULL_STALL_SECONDS = 300;

    /** The longest a single pull may take, however slowly it moves. */
    protected const PULL_TIMEOUT_SECONDS = 1200;

    public static function pullTimedOutMessage(string $image): string
    {
        return "Pulling {$image} stopped making progress. Your container runtime's network may be down or very slow: "
            .'restart Docker or OrbStack (on Windows, `wsl --shutdown`), check that `docker pull` works, then try again.';
    }

    /**
     * The lines worth showing: layer states that mean progress, and anything
     * unexpected. "Waiting" and the like repeat for every layer and say nothing.
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    public static function pullProgressLines(array $lines): array
    {
        $noise = '/: (Waiting|Pulling fs layer|Verifying Checksum)$/';

        return array_values(array_filter(
            array_map(trim(...), $lines),
            fn (string $line): bool => $line !== '' && preg_match($noise, $line) !== 1,
        ));
    }

    /**
     * Pull an image, printing each layer's progress as it happens. A failed pull
     * (not found, no access) comes back as a failed result for the caller to
     * handle; a stalled or endless one stops the command with a clear message.
     */
    protected function pullImage(string $image): ProcessResult
    {
        $buffer = '';
        $print = function (string $chunk) use (&$buffer): void {
            $buffer .= $chunk;
            $lines = preg_split('/\r\n|\r|\n/', $buffer) ?: [];
            $buffer = (string) array_pop($lines);

            $this->writePullProgress($lines);
        };

        try {
            $result = Process::timeout(self::PULL_TIMEOUT_SECONDS)
                ->idleTimeout(self::PULL_STALL_SECONDS)
                ->run($this->pullImageCommand($image), fn (string $type, string $output) => $print($output));
        } catch (ProcessTimedOutException) {
            throw new RuntimeException(self::pullTimedOutMessage($image));
        }

        $this->writePullProgress([$buffer]);

        return $result;
    }

    /**
     * @param  list<string>  $lines
     */
    private function writePullProgress(array $lines): void
    {
        foreach (self::pullProgressLines($lines) as $line) {
            $out = "  {$line}\n";
            State::isJsonMode() ? State::writeStderr($out) : State::writeStdout($out);
        }
    }
}
