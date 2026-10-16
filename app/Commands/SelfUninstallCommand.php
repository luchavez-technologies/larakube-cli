<?php

namespace App\Commands;

class SelfUninstallCommand extends UninstallCommand
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'self-uninstall
        {--all   : Remove all companion tools (~/.larakube/bin), cluster data, and shell PATH integration}
        {--purge : Delete the ~/.larakube configuration directory}
        {--force : Force uninstallation without interactive confirmation}';

    /**
     * The console command description.
     */
    protected $description = 'Remove the LaraKube CLI application and companion tools from your system';
}
