<?php

namespace App\Commands\Trust;

use App\Traits\InteractsWithTrust;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class CheckCommand extends Command
{
    use InteractsWithTrust, LaraKubeOutput;

    protected $signature = 'trust:check';

    protected $description = 'Diagnose the local HTTPS trust chain (CA, keychain, DNS, certs)';

    public function handle(): int
    {
        $this->renderHeader();
        $this->laraKubeInfo('Diagnosing local HTTPS trust chain...');
        $this->newLine();

        $issues = 0;
        $section = null;

        foreach ($this->diagnoseTrustChain() as $item) {
            if ($item['section'] !== $section) {
                if ($section !== null) {
                    $this->newLine();
                }
                $this->line("  <fg=cyan>{$item['section']}</>");
                $section = $item['section'];
            }

            $this->checkLine($item['ok'], $item['label']);

            if (! $item['ok']) {
                $issues++;

                // App cert fix commands are already embedded in the label itself
                // (e.g. "expired — run: larakube up"); a separate fix line there
                // would just repeat it.
                if ($item['fix'] && $item['section'] !== 'App certs') {
                    $this->line("    <fg=gray>→ Run: {$item['fix']}</>");
                }
            }
        }

        $this->newLine();

        // ── Summary ──────────────────────────────────────────────────────────
        if ($issues === 0) {
            $this->laraKubeInfo('All checks passed.');
        } else {
            $noun = $issues === 1 ? 'issue' : 'issues';
            $this->laraKubeWarn("{$issues} {$noun} found. See suggestions above.");
        }

        return $issues > 0 ? 1 : 0;
    }

    private function checkLine(bool $ok, string $label): void
    {
        $icon = $ok ? '<fg=green>✓</>' : '<fg=red>✗</>';
        $this->line("  {$icon}  {$label}");
    }
}
