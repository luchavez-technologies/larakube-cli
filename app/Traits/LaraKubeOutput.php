<?php

namespace App\Traits;

use App\Contracts\HasLifecycleHooks;
use App\Data\ConfigData;
use App\State;
use Illuminate\Support\Carbon;

use function Laravel\Prompts\table;
use function Termwind\render;

trait LaraKubeOutput
{
    use InteractsWithGlobalConfig;

    /**
     * Register a known-sensitive value (token, password, key) so any later
     * laraKube* output redacts it. No-op for trivial/short values. Call this
     * where you handle a secret you might otherwise echo. (State holds the
     * registry — this trait is mixed into enums, which can't have properties.)
     */
    public function registerSecret(?string $value): void
    {
        $value = trim((string) $value);
        if (strlen($value) >= 8) {
            State::$registeredSecrets[$value] = true;
        }
    }

    /**
     * Redact secrets from a line before printing: exact matches of values
     * registered via registerSecret(), plus a few high-confidence shapes
     * (Laravel APP_KEY, GitHub tokens, JWT / ServiceAccount tokens). Deliberately
     * narrow so it never mangles ordinary output. Applied to all laraKube*
     * output; reuse it at any `$this->line()` that may carry a secret.
     */
    public function maskSecrets(string $text): string
    {
        foreach (array_keys(State::$registeredSecrets) as $secret) {
            $text = str_replace($secret, '••••••', $text);
        }

        return preg_replace([
            '/base64:[A-Za-z0-9+\/]{30,}={0,2}/',                              // Laravel APP_KEY
            '/\bgh[posru]_[A-Za-z0-9]{20,}\b/',                                // GitHub PAT / OAuth / refresh / server / user
            '/\beyJ[A-Za-z0-9_-]{6,}\.[A-Za-z0-9_-]{6,}\.[A-Za-z0-9_-]{6,}/',  // JWT / k8s SA token
        ], '••••••', $text) ?? $text;
    }

    /**
     * Render the LaraKube header.
     */
    protected function renderHeader(): void
    {
        if (State::$headerRendered || app()->runningUnitTests()) {
            return;
        }

        $lines = [
            ' ██╗      █████╗ ██████╗  █████╗ ██╗  ██╗██╗   ██╗██████╗ ███████╗',
            ' ██║     ██╔══██╗██╔══██╗██╔══██╗██║ ██╔╝██║   ██║██╔══██╗██╔════╝',
            ' ██║     ███████║██████╔╝███████║█████╔╝ ██║   ██║██████╔╝█████╗  ',
            ' ██║     ██╔══██║██╔══██╗██╔══██║██╔═██╗ ██║   ██║██╔══██╗██╔══╝  ',
            ' ███████╗██║  ██║██║  ██║██║  ██║██║  ██╗╚██████╔╝██████╔╝███████╗',
            ' ╚══════╝╚═╝  ╚═╝╚═╝  ╚═╝╚═╝  ╚═╝╚═╝  ╚═╝ ╚═════╝ ╚═════╝ ╚══════╝',
        ];

        $gradients = [
            'Nordic' => [31, 31, 24, 24, 24, 23],
            'Slate' => [244, 242, 241, 240, 239, 238],
            'DeepSea' => [25, 25, 19, 18, 18, 17],
            'Forest' => [28, 28, 22, 22, 22, 22],
        ];

        $themeName = array_rand($gradients);
        $gradient = $gradients[$themeName];

        echo "\n";
        foreach ($lines as $index => $line) {
            $color = $gradient[$index] ?? 240;
            echo "  \e[38;5;{$color}m{$line}\e[0m\n";
        }

        render(<<<'HTML'
            <div class="mx-2 mt-2">
                <div class="px-2 py-0.5 bg-blue-900 text-blue-200 font-bold uppercase w-66 justify-center">
                    The Professional Kubernetes Orchestrator for Laravel
                </div>
            </div>
        HTML);

        State::$headerRendered = true;
    }

    /**
     * Render a LaraKube info line.
     */
    protected function laraKubeInfo(string $message): void
    {
        if ($this->isAiAgent()) {
            return;
        }

        $message = $this->stripConsoleTags($this->maskSecrets($message));
        render(<<<HTML
            <div class="flex mx-2 mt-1">
                <span class="px-1 bg-blue-500 text-white font-bold uppercase">LaraKube</span>
                <span class="ml-1 text-blue-500">{$message}</span>
            </div>
        HTML);
    }

    /**
     * Render the project's external service URLs ("Active Service Links") as a
     * Prompts table — the same view `about` shows. Lets `larakube up` (and any
     * other caller) surface vanity URLs inline so users don't have to run a
     * second command. Returns false (rendering nothing) when the environment
     * has no hosts, so callers can supply their own empty-state message.
     */
    protected function showServiceLinks(ConfigData $config, string $environment): bool
    {
        $hosts = $config->getAllHosts($environment);

        if ($hosts === []) {
            return false;
        }

        $this->laraKubeInfo('Active Service Links');

        $rows = [];
        foreach ($hosts as $host => $label) {
            $rows[] = [$label, "<fg=blue>https://{$host}</>"];
        }

        table(['Service', 'URL'], $rows);

        return true;
    }

    /**
     * Render each installed component's one-time post-install steps (e.g. MinIO's
     * bucket-creation walkthrough) — the same instructions `new`/`init`/`add` print
     * once and then can easily get lost (scrolled past, or the user comes back to
     * the project days later). Surfacing them again here — at the end of `up` and
     * in `about` — means they're always one command away instead of a one-time-only
     * printout. Silent no-op when nothing in the project has instructions.
     */
    protected function showArchitecturalInstructions(ConfigData $config): void
    {
        $instructions = [];
        foreach ($config->getComponents() as $component) {
            if ($component instanceof HasLifecycleHooks) {
                $instructions = array_merge($instructions, $component->getPostInstallInstructions($config));
            }
        }

        if ($instructions === []) {
            return;
        }

        $this->laraKubeInfo('One-time architectural steps');
        foreach ($instructions as $line) {
            $this->line("  $line");
        }
    }

    /**
     * Render a blank line.
     */
    protected function laraKubeNewLine(int $count = 1): void
    {
        for ($i = 0; $i < $count; $i++) {
            echo "\n";
        }
    }

    /**
     * Render a raw line of text.
     */
    protected function laraKubeLine(string $message): void
    {
        echo '  '.$this->stripConsoleTags($this->maskSecrets($message))."\n";
    }

    /**
     * Strip Symfony Console inline style tags (<fg=cyan>, </>, <options=bold>, etc.)
     * so messages passed to Termwind's render() don't leak raw tag syntax.
     */
    protected function stripConsoleTags(string $message): string
    {
        return preg_replace('/<[^>]+>/', '', $message) ?? $message;
    }

    /**
     * Render a warning line.
     */
    protected function laraKubeWarn(string $message): void
    {
        render("<div class='mx-2 mt-1 text-yellow-500'>".$this->stripConsoleTags($this->maskSecrets($message)).'</div>');
    }

    /**
     * Determine if the CLI is running inside an AI agent environment.
     */
    protected function isAiAgent(): bool
    {
        return env('AI_AGENT') === 'true' ||
               env('CURSOR') === 'true' ||
               env('GEMINI_CLI') === 'true' ||
               env('LARAKUBE_JSON') === '1' ||
               str_contains(implode(' ', $_SERVER['argv'] ?? []), 'mcp:start');
    }

    /**
     * Render a JSON response for AI agents.
     */
    protected function renderJson(array $data): int
    {
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";

        return 0;
    }

    /**
     * Render a polite GitHub star prompt once a week.
     */
    protected function renderStarPrompt(): void
    {
        $config = $this->getGlobalConfig();

        $lastShown = $config->getLastStarPromptAt();

        if (! $lastShown || $lastShown->diffInWeeks() > 1) {
            $this->newLine();
            $this->line('  <fg=yellow;options=bold>⭐ Enjoying LaraKube?</> If this tool helped you build a masterpiece, please consider starring us on GitHub:');
            $this->line('  <fg=gray>● CLI:</> <fg=blue;options=underscore>https://github.com/luchavez-technologies/larakube-cli</>');
            $this->line('  <fg=gray>● Console:</> <fg=blue;options=underscore>https://github.com/luchavez-technologies/larakube-console</>');
            $this->line('  <fg=gray>● Docs:</> <fg=blue;options=underscore>https://github.com/luchavez-technologies/larakube-docs</>');
            $this->newLine();
            $this->line('  <fg=magenta;options=bold>💖 Support the project:</> <fg=blue;options=underscore>https://github.com/sponsors/luchavez-technologies</>');
            $this->newLine();

            $config->setLastStarPromptAt(Carbon::now());
            $config->save();
        }
    }

    /**
     * Render a LaraKube error line.
     */
    protected function laraKubeError(string $message): void
    {
        $message = $this->maskSecrets($message);
        render(<<<HTML
            <div class="flex mx-2 mt-1">
                <span class="px-1 bg-red-500 text-white font-bold uppercase">LaraKube</span>
                <span class="ml-1 text-red-500">{$message}</span>
            </div>
        HTML);
    }

    /**
     * Run a task with a spinner.
     */
    protected function withSpin(string $message, callable $callback): mixed
    {
        return $this->task($message, $callback);
    }
}
