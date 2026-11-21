<?php

namespace App\Commands\Cloud;

use App\Data\StackData;
use App\Exceptions\MissingFlagException;
use App\Traits\ForgetsStacks;
use App\Traits\InteractsWithOpenTofu;
use App\Traits\InteractsWithRemoteSsh;
use App\Traits\LaraKubeOutput;
use App\Traits\ManagesDevBoxTunnel;
use App\Traits\RequiresFlagsWhenNonInteractive;

use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

/**
 * Remove a stack from this machine without touching the cloud: for a server that is gone, unreachable,
 * or under an account this machine can no longer use. Its record, OpenTofu state, SSH host key and kube
 * context go; whatever still runs at the provider keeps running, and billing, until deleted there.
 * cloud:destroy is the one that deletes the server itself.
 */
class CloudForgetCommand extends Command
{
    use ForgetsStacks, InteractsWithOpenTofu, InteractsWithRemoteSsh, LaraKubeOutput, ManagesDevBoxTunnel, RequiresFlagsWhenNonInteractive;

    protected $signature = 'cloud:forget
        {stack : The stack to forget}
        {--force : Forget it without asking to type its name}';

    protected $description = 'Remove a server from LaraKube without deleting it at the cloud provider';

    public function handle(): int
    {
        $this->renderHeader();

        $name = (string) $this->argument('stack');
        $stack = $this->getGlobalConfig()->findStack($name);

        if ($stack === null) {
            $this->laraKubeError("No registered stack named '{$name}'.");

            return 1;
        }

        $this->laraKubeWarn("This removes '{$stack->name}' from LaraKube only. Nothing is deleted at the provider:");
        foreach ($this->whereItLives($stack) as $label => $value) {
            $this->line("    <fg=gray>{$label}:</> {$value}");
        }
        $this->newLine();

        if (! $this->option('force')) {
            if ($this->cannotPrompt()) {
                throw new MissingFlagException('force', "confirm forgetting '{$stack->name}'", '--force');
            }

            $typed = text(label: "Type {$stack->name} to forget it", required: true);
            if ($typed !== $stack->name) {
                $this->laraKubeInfo('Nothing forgotten.');

                return 0;
            }
        }

        $this->forgetStackEverywhere($stack);
        $this->laraKubeInfo("Forgot '{$stack->name}'.");
        $this->line('  <fg=gray>If the server still exists, it keeps running and billing. Delete it in the provider\'s console.</>');

        return 0;
    }

    /**
     * Where the server lives, so it can be found and deleted by hand.
     *
     * @return array<string, string>
     */
    private function whereItLives(StackData $stack): array
    {
        $provider = match ($stack->provider) {
            'do' => 'DigitalOcean',
            'gcp' => 'Google Cloud',
            'aws' => 'AWS',
            'hetzner' => 'Hetzner',
            default => $stack->provider,
        };

        return array_filter([
            'Provider' => "{$provider} ({$stack->kind})",
            'Account' => $stack->account,
            'Project' => $stack->projectId,
            'Region' => $stack->region,
            'Address' => $stack->ip,
        ], fn (?string $value): bool => $value !== null && $value !== '');
    }
}
