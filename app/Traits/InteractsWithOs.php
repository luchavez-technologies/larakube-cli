<?php

namespace App\Traits;

use App\Support\Os;

trait InteractsWithOs
{
    protected function isDarwin(): bool
    {
        return Os::isDarwin();
    }

    protected function isLinux(): bool
    {
        return Os::isLinux();
    }

    protected function isWindows(): bool
    {
        return Os::isWindows();
    }
}
