<?php

namespace App\Commands\Mail;

use App\Data\ConfigData;
use App\Exceptions\MissingFlagException;
use App\Traits\InteractsWithCloudflareApi;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithMail;
use App\Traits\InteractsWithStalwartApi;
use App\Traits\LaraKubeOutput;
use App\Traits\RequiresFlagsWhenNonInteractive;

use function Laravel\Prompts\select;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

/**
 * Manage and inject outbound relay DNS records (AWS SES Easy DKIM CNAMEs, Custom MAIL FROM, Brevo)
 * into Cloudflare DNS using the zone's Cloudflare API token (auto-resolved from Stalwart).
 */
class MailDnsCommand extends Command
{
    use InteractsWithCloudflareApi, InteractsWithClusterContext, InteractsWithMail, InteractsWithStalwartApi, LaraKubeOutput, RequiresFlagsWhenNonInteractive;

    protected $signature = 'mail:dns
        {environment=local : Environment whose mail server to target}
        {--context= : Target a specific kube-context}
        {--zone= : Domain name in Cloudflare (e.g. partner.example)}
        {--cloudflare-token= : Cloudflare API token (auto-resolved from Stalwart if omitted)}
        {--provider= : Preset relay provider ("ses" or "brevo")}
        {--ses-tokens= : Comma-separated list of 3 SES DKIM tokens}
        {--region= : AWS region for SES (e.g. us-east-1)}
        {--mail-from= : Custom MAIL FROM subdomain prefix (e.g. "bounce")}';

    protected $description = 'Inject relay DNS records (AWS SES Easy DKIM, Brevo, custom MAIL FROM) into Cloudflare';

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');
        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;

        $context = (string) $this->option('context') ?: null;
        if (! $context && $config && $env !== 'local') {
            $context = $this->environmentContextOrCurrent($config, $env);
        }

        $kubectl = $this->mailKubectl($context);
        $ns = $this->mailNamespace();

        if (! $this->isMailInstalled($kubectl, $ns)) {
            $this->laraKubeError('Stalwart is not installed. Run `larakube mail:init` first.');

            return 1;
        }

        $zone = $this->resolveZone($env, $config);
        $token = $this->resolveToken($kubectl, $ns, $zone);

        $zoneId = $this->cloudflareZoneId($zone, $token);
        if ($zoneId === null) {
            $this->laraKubeError("Could not resolve Cloudflare Zone ID for '{$zone}'.");
            $this->line('  <fg=yellow>Diagnostics:</>');
            $this->line('  1. Check if the Cloudflare API token has <fg=green>Zone · DNS · Edit</> permissions on <fg=blue>'.$zone.'</>.');
            $this->line('  2. If you have an updated token, pass it with: <fg=blue>--cloudflare-token=<token></>');
            $this->newLine();

            return 1;
        }

        $provider = $this->resolveProvider();

        return match ($provider) {
            'ses' => $this->configureSesDns($zoneId, $token, $zone),
            'brevo' => $this->configureBrevoDns($zoneId, $token, $zone),
            default => $this->interactiveCustomDns($zoneId, $token, $zone),
        };
    }

    protected function configureSesDns(string $zoneId, string $token, string $zone): int
    {
        $tokensInput = (string) ($this->option('ses-tokens') ?? '');
        $tokens = [];

        if ($tokensInput !== '') {
            $tokens = array_values(array_filter(array_map('trim', explode(',', $tokensInput))));
        }

        if (count($tokens) < 3) {
            if ($this->cannotPrompt()) {
                throw new MissingFlagException(
                    'ses-tokens',
                    'the 3 SES Easy DKIM tokens (comma-separated)',
                    "larakube mail:dns production --zone={$zone} --provider=ses --ses-tokens=token1,token2,token3",
                );
            }

            $this->newLine();
            $this->info("Paste the 3 Easy DKIM tokens (or full CNAME names) from AWS SES for {$zone}:");
            $tokens = [];
            for ($i = 1; $i <= 3; $i++) {
                $raw = text(
                    label: "DKIM CNAME token #{$i}",
                    placeholder: 'e.g. 65lm7r4at5ch4rgd5xi3hoas7nb2g6fs',
                    required: true,
                );
                // Clean up token if user pasted the full domainkey name (e.g. abc._domainkey.domain.com)
                if (preg_match('/^([a-z0-9]+)\._domainkey/i', $raw, $m)) {
                    $tokens[] = $m[1];
                } else {
                    $tokens[] = trim($raw);
                }
            }
        }

        $rows = [];
        $success = true;

        $this->withSpin("Publishing 3 Easy DKIM CNAMEs to Cloudflare for {$zone}...", function () use (&$rows, &$success, $zoneId, $token, $zone, $tokens): void {
            foreach ($tokens as $t) {
                $name = "{$t}._domainkey.{$zone}";
                $target = "{$t}.dkim.amazonses.com";
                $ok = $this->cloudflareUpsertCnameRecord($zoneId, $token, $name, $target);
                if (! $ok) {
                    $success = false;
                }
                $rows[] = ['CNAME', $name, $target, $ok ? '✅ OK' : '❌ Failed'];
            }
        });

        // 1. Root SPF Record Update (authorizes SES to send for the root domain)
        $this->withSpin("Ensuring root SPF includes Amazon SES for {$zone}...", function () use (&$rows, &$success, $zoneId, $token, $zone): void {
            $rootSpf = 'v=spf1 mx include:amazonses.com ~all';
            $ok = $this->cloudflareUpsertTxtRecord($zoneId, $token, $zone, $rootSpf);
            if (! $ok) {
                $success = false;
            }
            $rows[] = ['TXT (SPF)', $zone, $rootSpf, $ok ? '✅ OK' : '❌ Failed'];
        });

        // 2. Custom MAIL FROM (Required for DMARC SPF Alignment)
        $mailFromPrefix = (string) ($this->option('mail-from') ?? '');
        if ($mailFromPrefix === '') {
            $mailFromPrefix = 'bounce';
        }

        if ($mailFromPrefix !== 'none') {
            $region = (string) ($this->option('region') ?: 'us-east-1');
            $mailFromName = "{$mailFromPrefix}.{$zone}";
            $mxTarget = "feedback-smtp.{$region}.amazonses.com";
            $spfContent = 'v=spf1 include:amazonses.com ~all';

            $this->withSpin("Publishing Custom MAIL FROM records for {$mailFromName}...", function () use (&$rows, &$success, $zoneId, $token, $mailFromName, $mxTarget, $spfContent): void {
                $mxOk = $this->cloudflareUpsertMxRecord($zoneId, $token, $mailFromName, $mxTarget, 10);
                $spfOk = $this->cloudflareUpsertTxtRecord($zoneId, $token, $mailFromName, $spfContent);

                if (! $mxOk || ! $spfOk) {
                    $success = false;
                }

                $rows[] = ['MX', $mailFromName, "10 {$mxTarget}", $mxOk ? '✅ OK' : '❌ Failed'];
                $rows[] = ['TXT (MAIL FROM SPF)', $mailFromName, $spfContent, $spfOk ? '✅ OK' : '❌ Failed'];
            });
        }

        $this->newLine();
        table(['Type', 'Name', 'Target / Content', 'Status'], $rows);
        $this->newLine();

        if ($success) {
            $this->laraKubeInfo("✅ Amazon SES DNS records published to Cloudflare for {$zone}.");
            $this->line('  <fg=gray>AWS SES will verify the domain identity and Custom MAIL FROM within a few minutes.</>');

            return 0;
        }

        $this->laraKubeError('Some records failed to update in Cloudflare. Check your token permissions.');

        return 1;
    }

    protected function configureBrevoDns(string $zoneId, string $token, string $zone): int
    {
        $code = (string) text(
            label: 'Brevo domain verification code (from Brevo dashboard → Domains)',
            placeholder: 'brevo-code:1234567890abcdef...',
            required: true,
        );

        $rows = [];
        $success = true;

        $this->withSpin("Publishing Brevo DNS records to Cloudflare for {$zone}...", function () use (&$rows, &$success, $zoneId, $token, $zone, $code): void {
            $txtOk = $this->cloudflareUpsertTxtRecord($zoneId, $token, $zone, $code);
            $d1Ok = $this->cloudflareUpsertCnameRecord($zoneId, $token, "brevo1._domainkey.{$zone}", 'b1.dkim.brevo.com');
            $d2Ok = $this->cloudflareUpsertCnameRecord($zoneId, $token, "brevo2._domainkey.{$zone}", 'b2.dkim.brevo.com');

            if (! $txtOk || ! $d1Ok || ! $d2Ok) {
                $success = false;
            }

            $rows[] = ['TXT', $zone, $code, $txtOk ? '✅ OK' : '❌ Failed'];
            $rows[] = ['CNAME', "brevo1._domainkey.{$zone}", 'b1.dkim.brevo.com', $d1Ok ? '✅ OK' : '❌ Failed'];
            $rows[] = ['CNAME', "brevo2._domainkey.{$zone}", 'b2.dkim.brevo.com', $d2Ok ? '✅ OK' : '❌ Failed'];
        });

        $this->newLine();
        table(['Type', 'Name', 'Target / Content', 'Status'], $rows);
        $this->newLine();

        if ($success) {
            $this->laraKubeInfo("✅ Brevo DNS records published to Cloudflare for {$zone}.");

            return 0;
        }

        $this->laraKubeError('Some Brevo records failed to update in Cloudflare.');

        return 1;
    }

    protected function interactiveCustomDns(string $zoneId, string $token, string $zone): int
    {
        $type = select(
            label: 'Record type to add/update',
            options: ['CNAME' => 'CNAME', 'TXT' => 'TXT', 'MX' => 'MX'],
            default: 'CNAME',
        );

        $name = (string) text(
            label: 'Record Name',
            placeholder: "e.g. sub.{$zone}",
            default: $zone,
            required: true,
        );

        $target = (string) text(
            label: $type === 'TXT' ? 'TXT Value' : 'Target Host',
            required: true,
        );

        $ok = false;
        $this->withSpin("Publishing {$type} record to Cloudflare...", function () use (&$ok, $zoneId, $token, $type, $name, $target): void {
            $ok = match ($type) {
                'CNAME' => $this->cloudflareUpsertCnameRecord($zoneId, $token, $name, $target),
                'TXT' => $this->cloudflareUpsertTxtRecord($zoneId, $token, $name, $target),
                'MX' => $this->cloudflareUpsertMxRecord($zoneId, $token, $name, $target),
            };
        });

        if ($ok) {
            $this->laraKubeInfo("✅ {$type} record {$name} → {$target} published to Cloudflare.");

            return 0;
        }

        $this->laraKubeError("Failed to publish {$type} record to Cloudflare.");

        return 1;
    }

    protected function resolveZone(string $env, ?ConfigData $config): string
    {
        $zone = (string) ($this->option('zone') ?? '');
        if ($zone !== '') {
            return $zone;
        }

        if ($this->cannotPrompt()) {
            throw new MissingFlagException(
                'zone',
                'the target DNS zone',
                'larakube mail:dns production --zone=partner.example',
            );
        }

        $host = $this->resolveMailHostReadOnly($env, $config);
        $defaultZone = $host ? (count(explode('.', $host)) > 2 ? implode('.', array_slice(explode('.', $host), 1)) : $host) : null;

        return (string) text(
            label: 'Which domain zone to configure in Cloudflare?',
            placeholder: 'partner.example',
            default: $defaultZone ?: 'partner.example',
            required: true,
        );
    }

    protected function resolveToken(string $kubectl, string $ns, string $zone): string
    {
        $token = (string) ($this->option('cloudflare-token') ?? '');
        if ($token !== '') {
            return $token;
        }

        // Auto-discover from Stalwart's stored DnsServer object
        $discovered = $this->stalwartGetZoneCloudflareToken($kubectl, $ns, $zone);
        if ($discovered !== null && $discovered !== '') {
            $this->laraKubeInfo("Auto-discovered Cloudflare API token for '{$zone}' from Stalwart.");

            return $discovered;
        }

        if ($this->cannotPrompt()) {
            throw new MissingFlagException(
                'cloudflare-token',
                "the Cloudflare API token for {$zone}",
                "larakube mail:dns production --zone={$zone} --cloudflare-token=…",
            );
        }

        return (string) text(
            label: "Cloudflare API token for {$zone}",
            required: true,
        );
    }

    protected function resolveProvider(): string
    {
        $provider = (string) ($this->option('provider') ?? '');
        if ($provider !== '') {
            return strtolower($provider);
        }

        if ($this->cannotPrompt()) {
            return 'ses';
        }

        return select(
            label: 'Which relay provider DNS records would you like to publish?',
            options: [
                'ses' => 'Amazon SES (Easy DKIM CNAMEs + Custom MAIL FROM)',
                'brevo' => 'Brevo (DKIM CNAMEs + Verification TXT)',
                'custom' => 'Custom DNS Record (CNAME / TXT / MX)',
            ],
            default: 'ses',
        );
    }
}
