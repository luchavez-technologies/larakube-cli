<?php

namespace App\Traits;

use Illuminate\Support\Facades\Process;

trait InteractsWithLaraKubeCli
{
    /**
     * Get the resolved LaraKube binary path for the current environment.
     */
    protected function getLaraKubeBinary(): string
    {
        // 1. If we are in the development container, use the source path
        if (file_exists('/larakube/larakube')) {
            return 'php /larakube/larakube';
        }

        // 2. If we are in the source directory (e.g. CI or local dev without Docker), use the local source
        if (file_exists(base_path('larakube'))) {
            return 'php '.base_path('larakube');
        }

        // 3. Use the current executing binary path (Self-referential)
        // This ensures the standalone binary (e.g. /usr/local/bin/larakube) calls itself correctly.
        $self = $_SERVER['argv'][0] ?? 'larakube';

        // If it's a relative path, try to make it absolute for reliability
        if (file_exists($self)) {
            return realpath($self);
        }

        return $self;
    }

    /**
     * Get the raw list of all available LaraKube commands.
     */
    protected function listCliCommands(): string
    {
        $bin = $this->getLaraKubeBinary();

        return Process::run("{$bin} list --raw")->output();
    }

    /**
     * Get the help output for a specific command.
     */
    protected function getCliCommandHelp(string $command): string
    {
        $bin = $this->getLaraKubeBinary();
        $output = Process::run("{$bin} help {$command}")->output();

        return $output !== '' ? $output : "No help found for command: {$command}";
    }

    /**
     * Execute a LaraKube command with built-in safety and automation flags.
     */
    protected function executeCliCommand(string $command): array
    {
        $bin = $this->getLaraKubeBinary();

        // Security: Remove 'larakube' prefix if the AI included it, we will add it back correctly
        $command = preg_replace('/^larakube\s+/', '', $command);

        $finalCommand = "{$bin} {$command}";

        // Add non-interactive flags automatically
        if (! str_contains($finalCommand, '--no-interaction')) {
            $finalCommand .= ' --no-interaction';
        }

        // Force destruction for safety/automation
        if (str_contains($finalCommand, ' down') && ! str_contains($finalCommand, '--force')) {
            $finalCommand .= ' --force';
        }

        // No timeout — the wrapped command can be an arbitrary LaraKube
        // orchestration command (e.g. `up`, `down`) that legitimately runs
        // long, and this preserves exec()'s previous "wait as long as it
        // takes" behavior.
        $result = Process::forever()->run($finalCommand);

        return [
            'command' => $finalCommand,
            'output' => trim($result->output().$result->errorOutput()),
            'exit_code' => $result->exitCode(),
            'success' => $result->successful(),
        ];
    }

    /**
     * Get the unified AI instructions with dynamic project context.
     */
    protected function getAiInstructions(): string
    {
        $path = base_path('resources/ai/larakube-assistant.md');
        $instructions = file_exists($path) ? file_get_contents($path) : 'You are LaraKube, a professional autonomous Kubernetes orchestrator for Laravel.';

        // 1. Detect existing LaraKube project
        $isLaraKubeProject = file_exists(getcwd().'/.larakube.json');

        // 2. Detect uninitialized Laravel project (Must have BOTH)
        $isLaravelProject = file_exists(getcwd().'/composer.json') && file_exists(getcwd().'/artisan');

        if ($isLaraKubeProject) {
            $context = "\n\n### CURRENT CONTEXT:\n- You ARE currently inside an existing LaraKube project (detected .larakube.json).\n- DO NOT suggest or execute 'new' or 'init' here to avoid conflicts.\n- Focus on 'add', 'up', 'down', 'heal', or 'doctor' commands.";
        } elseif ($isLaravelProject) {
            $context = "\n\n### CURRENT CONTEXT:\n- You are inside a Laravel project that is NOT yet a LaraKube project.\n- Suggest 'init' to initialize LaraKube for this project.\n- DO NOT suggest 'new' here as it would create a nested directory.";
        } else {
            $context = "\n\n### CURRENT CONTEXT:\n- You are in a blank or non-Laravel directory.\n- Suggest 'new' to create a fresh LaraKube project from scratch.";
        }

        return $instructions.$context;
    }
}
