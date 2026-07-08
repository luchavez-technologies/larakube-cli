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

#[Name('orchestrate-verb')]
#[Title('Orchestrate Verb')]
#[Description('Executes a LaraKube orchestration command like "up", "down", "heal", or "doctor".')]
class OrchestrateVerbTool extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $originalCwd = getcwd();
        chdir($_ENV['GEMINI_WORKSPACE_ROOT'] ?? getcwd());

        $verb = $request->get('verb');
        $args = $request->get('args');
        $path = $request->get('path') ?: getcwd();

        if (! is_dir($path)) {
            chdir($originalCwd);

            return Response::error("Error: Project directory not found at '{$path}'.");
        }

        $allowedVerbs = ['up', 'down', 'heal', 'add', 'remove', 'start', 'stop', 'purge', 'status', 'doctor', 'trust', 'trust:remove', 'trust:check', 'trust:reset', 'about', 'web', 'console'];

        if (! in_array($verb, $allowedVerbs)) {
            chdir($originalCwd);

            return Response::error("Error: Verb '{$verb}' is not an orchestration command.");
        }

        chdir($path);

        $command = "larakube {$verb}";
        if ($args) {
            $command .= " {$args}";
        }

        $result = Process::run($command.' --no-ansi --no-interaction');
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
            'verb' => $schema->string()
                ->description('The command to run (up, down, heal, add, remove, doctor, trust, about)'),
            'args' => $schema->string()
                ->description('Optional flags or arguments for the command (e.g. "--force" or "meilisearch")')
                ->nullable(),
            'path' => $schema->string()
                ->description('The filesystem path of the project (optional)')
                ->nullable(),
        ];
    }
}
