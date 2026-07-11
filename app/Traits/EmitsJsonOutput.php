<?php

namespace App\Traits;

use App\State;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Output\ConsoleOutputInterface;

/**
 * Machine-readable output mode for commands driven headlessly (LaraKube
 * Cloud job containers): stdout is reserved for one jsonOutput() result and
 * every human-readable channel goes to stderr. Lives apart from
 * LaraKubeOutput because it needs $this->output/$this->input — that trait is
 * also mixed into enums and data classes, which have neither.
 */
trait EmitsJsonOutput
{
    /**
     * Emit one machine-readable JSON result on the command's ORIGINAL stdout —
     * under JSON mode $this->output has been rerouted to stderr, so this is
     * the only writer that still reaches stdout. The single line a headless
     * caller parses.
     */
    protected function jsonOutput(array $data): void
    {
        (State::$stdout ?? $this->output)->writeln(json_encode($data, JSON_UNESCAPED_SLASHES));
    }

    /**
     * Reserve stdout for one jsonOutput() result and reroute every
     * human-readable channel to stderr: Termwind's render() sink (all
     * laraKube* helpers, including ones in shared traits) and $this->output
     * ($this->line()/newLine()/task()). Raw-echo sites (laraKubeLine,
     * renderHeader, runStreaming) check State::$jsonMode themselves. Under
     * tests the output isn't a ConsoleOutputInterface, so everything stays
     * on the capturable buffer.
     */
    protected function enableJsonMode(): void
    {
        State::$jsonMode = true;
        State::$stdout = $this->output;

        $out = $this->output->getOutput();
        $stderr = $out instanceof ConsoleOutputInterface
            ? $out->getErrorOutput()
            : $out;

        \Termwind\renderUsing($stderr);
        $this->setOutput(new OutputStyle($this->input, $stderr));
    }
}
