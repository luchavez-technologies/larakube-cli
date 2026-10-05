<?php

namespace App\Traits;

/** The failing exit of a command that can emit one JSON result: the message to the human channel, and `success: false` on stdout under --json. */
trait FailsWithJson
{
    protected function failed(string $message): int
    {
        $this->laraKubeError($message);

        if ($this->flag('json')) {
            $this->jsonOutput(['success' => false, 'error' => $message]);
        }

        return 1;
    }
}
