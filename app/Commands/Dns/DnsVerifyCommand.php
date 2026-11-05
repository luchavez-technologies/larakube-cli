<?php

namespace App\Commands\Dns;

use App\Enums\DnsProvider;
use App\Exceptions\MissingFlagException;
use App\Traits\EmitsJsonOutput;
use App\Traits\FailsWithJson;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsCommandOptions;
use App\Traits\RequiresFlagsWhenNonInteractive;

use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class DnsVerifyCommand extends Command
{
    use EmitsJsonOutput, FailsWithJson, LaraKubeOutput, ReadsCommandOptions, RequiresFlagsWhenNonInteractive;

    protected $signature = 'dns:verify
        {--provider=cloudflare : The DNS provider (cloudflare, route53, google)}
        {--token= : The API token to verify}
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = 'Verify DNS and SSL credentials and permissions for a DNS provider';

    public function handle(): int
    {
        if ($this->flag('json')) {
            $this->enableJsonMode();
        }

        $providerSlug = (string) ($this->option('provider') ?: DnsProvider::CLOUDFLARE->value);
        $provider = DnsProvider::tryFrom($providerSlug);

        if ($provider === null) {
            return $this->failed("Unknown DNS provider '{$providerSlug}'. Supported: cloudflare, route53, google.");
        }

        $token = (string) ($this->option('token') ?: getenv('LARAKUBE_CLOUDFLARE_TOKEN') ?: '');

        if ($token === '') {
            if ($this->cannotPrompt()) {
                throw new MissingFlagException('token', 'the DNS provider token or API credential', 'larakube dns:verify --token=…');
            }

            $token = (string) text(
                label: "{$provider->label()} API token",
                placeholder: 'Paste your token',
                required: true,
            );
        }

        $result = null;

        if ($this->flag('json')) {
            $result = $provider->verifyCredential(['token' => $token]);
            $this->jsonOutput($result);

            return $result['valid'] ? 0 : 1;
        }

        $this->renderHeader();
        $this->line("Probing {$provider->label()} credentials and permissions...");

        spin(function () use ($provider, $token, &$result): void {
            $result = $provider->verifyCredential(['token' => $token]);
        }, 'Contacting API...');

        if ($result === null) {
            $this->laraKubeError('Verification failed to return a response.');

            return 1;
        }

        $rows = [
            [
                'Token Active',
                $result['checks']['token_active'] ? '✓ Valid & active' : '✗ Invalid or expired',
            ],
            [
                'Zones Accessible',
                $result['checks']['zones_accessible']
                    ? '✓ Found '.count($result['zones']).' zone(s)'
                    : '✗ No zones found in scope',
            ],
            [
                'Zone → DNS → Edit',
                $result['checks']['dns_edit'] ? '✓ Granted' : '✗ Missing (Required for ExternalDNS)',
            ],
            [
                'Zone → Zone Settings → Read',
                $result['checks']['ssl_read'] ? '✓ Granted' : '✗ Missing (Required for SSL mode discovery / 9109)',
            ],
        ];

        table(['Check', 'Status'], $rows);

        if ($result['zones'] !== []) {
            $this->newLine();
            $this->line('  <fg=gray>Accessible Zones:</> '.implode(', ', $result['zones']));
        }

        if ($result['valid']) {
            $this->newLine();
            $this->laraKubeInfo("{$provider->label()} credentials verified successfully.");

            return 0;
        }

        $this->newLine();
        $this->laraKubeError("{$provider->label()} credentials have missing permissions.");
        foreach ($result['errors'] as $error) {
            $this->line("  <fg=red>•</> {$error}");
        }

        return 1;
    }
}
