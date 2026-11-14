<?php

namespace App\Contracts\CliTools;

/**
 * A CLI tool with a verified native Windows installer (no WSL round-trip).
 * Only tools implementing this are offered on a native Windows host;
 * `setup --tools=…` hands the rest to the larakube-ubuntu distro.
 */
interface InstallsOnWindows
{
    /** Download (or re-download, overwriting) the native Windows build. */
    public function installOnWindows(): bool;
}
