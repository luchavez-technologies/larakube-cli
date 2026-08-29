<?php

namespace App\Commands\Mail;

use App\Data\ConfigData;
use App\Exceptions\MissingFlagException;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithMail;
use App\Traits\InteractsWithStalwartApi;
use App\Traits\LaraKubeOutput;
use App\Traits\RequiresFlagsWhenNonInteractive;

use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

/**
 * Onboard an additional mail domain onto the existing shared Stalwart
 * instance, with fully automatic DNS, TLS, and DKIM management — no manual
 * admin-UI clicking, no Cloudflare dashboard access required (only the
 * zone's own API token).
 *
 * This is deliberately additive, not a replacement for mail:init's manual
 * first-domain setup: luchtech.dev's own domain stays exactly as configured.
 */
class MailDomainCommand extends Command
{
    use ConfirmsDestructiveAction, InteractsWithClusterContext, InteractsWithMail, InteractsWithStalwartApi, LaraKubeOutput, RequiresFlagsWhenNonInteractive;

    protected $signature = 'mail:domain
        {environment=local   : Environment whose mail server to target}
        {--context=          : Target a specific kube-context}
        {--zone=              : Domain to onboard, e.g. partner.example}
        {--cloudflare-token=  : API token for THIS zone (scope it to this zone only)}
        {--acme-email=        : Contact email for the ACME account (only used if a new one is created)}
        {--force              : Skip the confirmation prompt}';

    protected $description = 'Onboard an additional domain onto the shared Stalwart mail server';

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

        $zone = $this->resolveZone();
        $token = $this->resolveToken($zone);
        $acmeEmail = (string) $this->option('acme-email') ?: null;

        if (! $this->confirmDestructive([
            "Stalwart will take over DNS for '{$zone}':",
            'MX/SPF/DKIM/DMARC/CAA/autoconfig/autodiscover/SRV/MTA-STS/TLS-RPT records are',
            'created and kept in sync directly against Cloudflare using the supplied token,',
            'and a Let\'s Encrypt certificate is issued automatically via DNS-01.',
        ])) {
            return 0;
        }

        // withSpin()/Command::task() always returns a bool (success signal),
        // never the closure's own return value — capture by reference instead
        // of assigning the call's result, matching the established pattern
        // (e.g. UpCommand's manifest-validation/DotenvPushCommand's sync
        // steps), not MailCreateCommand's account-creation step, which
        // mistakenly assigns the call result directly.
        $dnsServerId = null;
        $this->withSpin(
            "Registering the Cloudflare DNS server for {$zone}...",
            function () use (&$dnsServerId, $kubectl, $ns, $zone, $token, $acmeEmail): void {
                $dnsServerId = $this->stalwartUpsertCloudflareDnsServer($kubectl, $ns, $zone, $token, $acmeEmail);
            },
        );

        if ($dnsServerId === null) {
            $this->laraKubeError('Failed to register the DNS server with Stalwart. Check the JMAP connection.');

            return 1;
        }

        $acmeProviderId = null;
        $this->withSpin(
            'Resolving an ACME provider (reusing an existing one when possible)...',
            function () use (&$acmeProviderId, $kubectl, $ns, $acmeEmail): void {
                $acmeProviderId = $this->stalwartEnsureAcmeProvider($kubectl, $ns, $acmeEmail);
            },
        );

        if ($acmeProviderId === null) {
            $this->laraKubeError('Failed to resolve an ACME provider. Check the JMAP connection.');

            return 1;
        }

        $domainId = null;
        $this->withSpin(
            "Onboarding {$zone} with automatic DNS/TLS/DKIM management...",
            function () use (&$domainId, $kubectl, $ns, $zone, $dnsServerId, $acmeProviderId): void {
                $domainId = $this->stalwartUpsertDomain($kubectl, $ns, $zone, $dnsServerId, $acmeProviderId);
            },
        );

        if ($domainId === null) {
            $this->laraKubeError('Failed to create/update the domain in Stalwart.');

            return 1;
        }

        // Defensive: the create call above already sets RSA-only DKIM
        // explicitly, but re-running this here is cheap insurance against the
        // same duplicate-signature condition mail:check/mail:dkim guard
        // against elsewhere.
        $this->stalwartEnforceSingleRsaDkimSignature($kubectl, $ns);

        $this->newLine();
        $this->laraKubeInfo("✅ {$zone} is now a fully automatic Stalwart domain.");
        $this->newLine();
        $this->line("  <fg=gray>Zone:</>   <fg=blue>{$zone}</>");
        $this->line('  <fg=gray>DNS/TLS/DKIM:</> <fg=blue>Automatic</> (via Cloudflare + Let\'s Encrypt DNS-01)');
        $this->newLine();

        $relayProvider = $this->readClusterSecretKey($kubectl, $ns, 'mail-relay', 'provider');
        if ($relayProvider !== null && $relayProvider !== '') {
            $this->line('  <fg=yellow>Outbound Relay Detected ('.$relayProvider.'):</>');
            $this->line("  Publish DKIM/SPF relay records with: <fg=blue>larakube mail:dns {$env} --zone={$zone} --provider={$relayProvider}</>");
            $this->newLine();
        }

        $this->line('  <fg=gray>Create a mailbox on this domain:</>');
        $this->line("  <fg=blue>larakube mail:create {$env} --email=alice@{$zone} --sso</>");
        $this->newLine();

        return 0;
    }

    /**
     * The domain to onboard. Required — no default, matching dns:init's
     * refusal to guess at a destructive per-zone action.
     */
    protected function resolveZone(): string
    {
        return $this->flagOrPrompt(
            'zone',
            fn () => text(
                label: 'Which domain should Stalwart onboard with automatic management?',
                placeholder: 'partner.example',
                required: true,
            ),
            'the domain to onboard',
            'larakube mail:domain production --zone=partner.example',
        );
    }

    /**
     * The API token for this zone. Never persisted outside Stalwart's own
     * x:DnsServer object — see stalwartUpsertCloudflareDnsServer().
     */
    protected function resolveToken(string $zone): string
    {
        $token = (string) ($this->option('cloudflare-token') ?? '');
        if ($token !== '') {
            return $token;
        }

        if ($this->cannotPrompt()) {
            throw new MissingFlagException(
                'cloudflare-token',
                "the Cloudflare API token for {$zone}",
                "larakube mail:domain production --zone={$zone} --cloudflare-token=…",
            );
        }

        $this->newLine();
        $this->info("Create a Cloudflare API token scoped to {$zone}:");
        $this->line('  1. <fg=blue>https://dash.cloudflare.com/profile/api-tokens</>');
        $this->line('  2. Create Token → Create Custom Token');
        $this->line('  3. Permissions: <fg=yellow>Zone</> · <fg=yellow>DNS</> · <fg=yellow>Edit</>');
        $this->line("  4. Zone Resources: <fg=yellow>Include</> · <fg=yellow>Specific Zone</> · <fg=yellow>{$zone}</>");
        $this->newLine();

        return (string) text(label: "Cloudflare API token for {$zone}", required: true);
    }
}
