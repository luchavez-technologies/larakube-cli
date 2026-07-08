<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Process;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;

#[Name('run-proxy')]
#[Title('Transparent Cluster Proxy')]
#[Description('Runs artisan, composer, php, or npm commands directly inside the cluster. Automatically handles the larakube proxying.')]
class RunProxyTool extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $originalCwd = getcwd();
        chdir($_ENV['GEMINI_WORKSPACE_ROOT'] ?? getcwd());

        $tool = $request->get('tool');
        $command = $request->get('command');
        $path = $request->get('path') ?: getcwd();

        if (! is_dir($path)) {
            chdir($originalCwd);

            return Response::error("Error: Project directory not found at '{$path}'.");
        }

        $allowedTools = ['art', 'artisan', 'composer', 'php', 'npm', 'node', 'bun', 'pnpm', 'yarn'];

        if (! in_array($tool, $allowedTools)) {
            chdir($originalCwd);

            return Response::error("Error: Tool '{$tool}' is not a supported LaraKube proxy tool.");
        }

        chdir($path);

        $normalizedTool = $tool === 'artisan' ? 'art' : $tool;

        $fullCommand = "larakube {$normalizedTool} {$command}";

        $result = Process::run($fullCommand.' --no-ansi --no-interaction');
        $output = trim($result->output().$result->errorOutput());
        chdir($originalCwd);

        return Response::text($output !== '' ? $output : 'Command executed successfully (no output).');
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'tool' => $schema->string()->enum(['artisan', 'composer', 'php', 'npm', 'node', 'bun'])->description('The tool to proxy'),
            'command' => $schema->string()->description('The full command and arguments (e.g. "migrate --force" or "install")'),
            'path' => $schema->string()->description('The filesystem path of the project (optional)')->nullable(),
        ];
    }
}
