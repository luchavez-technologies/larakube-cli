<?php

namespace App\Commands;

use App\Enums\CliTool;
use App\Enums\InstallChannel;
use App\Services\CliTools\AbstractCliTool;
use App\Traits\EmitsJsonOutput;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsCommandOptions;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

/**
 * Read-only report of the CLI tools `setup --tools` manages, resolved exactly
 * the way every other command resolves them (CliTool::resolveBinary()). GUIs
 * (LaraKube Desktop) read this instead of keeping their own copy of where
 * kubectl/tofu/gcloud/aws live on each OS, so the two can never disagree.
 */
class SetupStatusCommand extends Command
{
    use EmitsJsonOutput, LaraKubeOutput, ReadsCommandOptions;

    protected $signature = 'setup:status
        {tool? : Report only this tool (kubectl, k9s, tofu, gcloud, aws, hcloud, gh, tea)}
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = 'Show where each CLI tool setup manages is installed, and its version';

    public function handle(): int
    {
        $requested = $this->argument('tool');
        $tools = CliTool::cases();

        if (is_string($requested) && $requested !== '') {
            $tool = CliTool::tryFrom(strtolower($requested));

            if ($tool === null) {
                $valid = implode(', ', array_map(fn (CliTool $case): string => $case->value, CliTool::cases()));

                if ($this->flag('json')) {
                    $this->jsonOutput(['success' => false, 'error' => "Unknown tool '{$requested}'. Valid tools: {$valid}."]);
                } else {
                    $this->laraKubeError("Unknown tool '{$requested}'. Valid tools: {$valid}.");
                }

                return 1;
            }

            $tools = [$tool];
        }

        // The explicit "check again": resolve every tool afresh and re-record it in ~/.larakube/tools.json.
        $rows = array_map(fn (CliTool $tool): array => $tool->status(fresh: true), $tools);

        if ($this->flag('json')) {
            $this->jsonOutput(['success' => true, 'os' => AbstractCliTool::hostOs(), 'binDir' => CliTool::binDir(), 'tools' => $rows]);

            return 0;
        }

        table(
            headers: ['Tool', 'Installed', 'Managed by', 'Version', 'Path'],
            rows: array_map(fn (array $row): array => [
                $row['label'],
                $row['installed'] ? 'yes' : ($row['installable'] ? 'no' : 'no (not installable here)'),
                $row['channel'] !== null ? InstallChannel::from($row['channel'])->label() : '—',
                $row['version'] ?? '—',
                $row['path'] ?? '—',
            ], $rows),
        );

        return 0;
    }
}
