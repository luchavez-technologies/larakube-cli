<?php

namespace App\Traits;

use App\Enums\CliTool;
use App\State;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

trait InteractsWithHetzner
{
    use InteractsWithGlobalConfig;

    /**
     * Prompt for + persist Hetzner Cloud API token, ensuring active credentials.
     */
    protected function ensureHetznerToken(): bool
    {
        if ($flagToken = $this->flag('hetzner-token')) {
            State::$transientHetznerToken = trim($flagToken);
            $this->registerSecret(State::$transientHetznerToken);

            return true;
        }

        if ($envToken = getenv('HCLOUD_TOKEN') ?: getenv('HETZNER_TOKEN')) {
            State::$transientHetznerToken = trim($envToken);
            $this->registerSecret(State::$transientHetznerToken);

            return true;
        }

        if ($this->getHetznerToken()) {
            return true;
        }

        if ($this->flag('no-interaction')) {
            $this->laraKubeError('No Hetzner Cloud API token. Pass --hetzner-token= or set HCLOUD_TOKEN when running non-interactively.');

            return false;
        }

        // Offer hcloud CLI install if missing and running interactively
        if (! CliTool::HCLOUD->isInstalled() && ! $this->flag('no-interaction') && $this->output !== null) {
            if (confirm('Hetzner Cloud CLI (hcloud) is not installed. Would you like to install it now via larakube setup?', default: false)) {
                $this->call('setup', ['--tools' => 'hcloud']);
            }
        }

        $this->laraKubeWarn('No Hetzner Cloud API token found.');
        $this->line('  <fg=gray>Generate one at</> https://console.hetzner.cloud <fg=gray>(Project > Security > API Tokens, Read & Write).</>');
        $token = text(
            label: 'Paste your Hetzner Cloud API token',
            required: true,
            hint: 'Stored in ~/.larakube and passed to OpenTofu via TF_VAR_hcloud_token (never written into HCL).',
        );

        $this->setHetznerToken($token);
        $this->registerSecret($token);
        $this->laraKubeInfo('Saved Hetzner Cloud token to your global LaraKube config.');

        return true;
    }
}
