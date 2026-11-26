<?php

namespace App\Support\Console;

use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Captures a sub-command's output and streams it to a runner Job's log as it runs, masking the
 * value on any line that names a credential. An install prints the admin password it generated, and
 * LaraKube Cloud shows and keeps that log, so the value never reaches it. The capture is masked
 * too, because its last lines become the error LaraKube Cloud stores.
 */
final class MaskedLogOutput extends BufferedOutput
{
    private const CREDENTIAL = '/pass(word|phrase)?\b|secret|token|api[ _-]?key|private[ _-]?key|credential|\blogin\b/i';

    /** Set when a line introduced a credential on the next line ("use the password directly:"). */
    private bool $maskNext = false;

    public function __construct(private readonly OutputInterface $log)
    {
        parent::__construct($log->getVerbosity(), false);
    }

    public function mask(string $message): string
    {
        $parts = preg_split('/(\R)/u', $message, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$message];

        foreach ($parts as $i => $line) {
            if (preg_match('/^\R$/u', $line) === 1) {
                continue;
            }

            $plain = trim((string) preg_replace('/\e\[[0-9;]*m|<\/?[a-z][a-z=;,\- ]*>/i', '', $line));
            if ($plain === '') {
                continue;
            }

            if ($this->maskNext) {
                $this->maskNext = false;
                $parts[$i] = '  ••••••';

                continue;
            }

            // "admin / <password>" or "user@example.com / <password>": hide what follows the slash.
            if (preg_match('/^(.*\S\s+\/)\s+\S+$/u', $plain, $pair) === 1 && preg_match('/admin|login|@/i', $pair[1]) === 1) {
                $parts[$i] = '  '.$pair[1].' ••••••';

                continue;
            }

            if (preg_match(self::CREDENTIAL, $plain) !== 1) {
                continue;
            }

            if (str_ends_with($plain, ':')) {
                $this->maskNext = true;

                continue;
            }

            $colon = strpos($plain, ':');
            $parts[$i] = $colon === false ? $line : '  '.substr($plain, 0, $colon + 1).' ••••••';
        }

        return implode('', $parts);
    }

    protected function doWrite(string $message, bool $newline): void
    {
        $masked = $this->mask($message);
        parent::doWrite($masked, $newline);
        $this->log->write($masked, $newline, OutputInterface::OUTPUT_RAW);
    }
}
