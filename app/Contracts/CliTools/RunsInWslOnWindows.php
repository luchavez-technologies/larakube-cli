<?php

namespace App\Contracts\CliTools;

/**
 * A CLI tool that lives inside the larakube-ubuntu distro on Windows, because
 * the work it does is project work and projects live there (gh, tea). The
 * image bundles it, and the Windows-side CLI runs the distro's copy through
 * `wsl.exe` instead of installing a second one on the host.
 */
interface RunsInWslOnWindows {}
