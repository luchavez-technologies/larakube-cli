<?php

namespace App\Commands;

use App\Traits\InteractsWithGlobalConfig;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class ConfigCommand extends Command
{
    use InteractsWithGlobalConfig, LaraKubeOutput;

    protected $signature = 'config {--email= : Update the global administrator email}';

    protected $description = 'View and manage global LaraKube settings';

    public function handle()
    {
        $this->renderHeader();

        $email = $this->option('email');
        if ($email) {
            $this->setEmail($email);
            $this->info("  ✔ Global email updated to: $email");
        }

        $this->laraKubeInfo('Current Global Configuration:');

        $this->info('👤 General Settings');
        $this->line('  <fg=gray>● Admin Email:</> '.($this->getEmail() ?? '<fg=red>Not Set</>'));
        $this->line('  <fg=gray>● Local TLD:</> <fg=green>.'.$this->getLocalTld().'</>');

        $trusted = $this->checkCaTrust();
        $this->line('  <fg=gray>● Local HTTPS:</> '.($trusted ? '<fg=green>Trusted ✅</>' : '<fg=yellow>Not Trusted ⚠</>'));
        $this->line('');

        $this->info('🧠 AI Orchestration');
        $this->line('  <fg=gray>● Default Provider:</> '.$this->getAiProvider());
        $this->line('  <fg=gray>● Anthropic Key:</> '.($this->getAiApiKey('anthropic') ? '********' : '<fg=red>Not Set</>'));
        $this->line('  <fg=gray>● OpenAI Key:</> '.($this->getAiApiKey('openai') ? '********' : '<fg=red>Not Set</>'));
        $this->line('  <fg=gray>● Gemini Key:</> '.($this->getAiApiKey('gemini') ? '********' : '<fg=red>Not Set</>'));

        $this->line('');
        $this->info('👉 To update AI settings: larakube config:ai --anthropic=KEY');
        $this->info('👉 To update email: larakube config --email=example@email.com');
        $this->info('👉 To change local TLD: larakube config:tld localhost');

        return 0;
    }
}
