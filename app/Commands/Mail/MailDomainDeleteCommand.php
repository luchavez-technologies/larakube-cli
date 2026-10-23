<?php

namespace App\Commands\Mail;

use App\Data\ConfigData;
use App\Services\Kubectl;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithMail;
use App\Traits\InteractsWithStalwartApi;
use App\Traits\LaraKubeOutput;
use App\Traits\RequiresFlagsWhenNonInteractive;

use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

/**
 * Permanently removes a domain from the shared Stalwart mail server: every
 * account/mailbox under it, its DKIM signing keys, the domain object itself,
 * and its own DNS automation config. Deliberately does NOT touch any live
 * Cloudflare DNS records — only Stalwart's own JMAP-side objects.
 *
 * Built for offboarding a client whose domain is still configured here after
 * the operator no longer controls its Cloudflare zone/token: left in place,
 * an Automatic domain with a permanently-failing DNS-01 challenge can drag
 * down ACME renewal for every OTHER domain sharing the same x:AcmeProvider —
 * which this command never touches, since that provider legitimately serves
 * every domain on this instance.
 */
class MailDomainDeleteCommand extends Command
{
    use InteractsWithClusterContext, InteractsWithMail, InteractsWithStalwartApi, LaraKubeOutput, RequiresFlagsWhenNonInteractive;

    protected $signature = 'mail:domain-delete
        {environment=local : Environment whose mail server to target}
        {--context=         : Target a specific kube-context}
        {--domain=          : Domain to permanently remove from Stalwart}
        {--force            : Skip the confirmation prompt}';

    protected $description = 'Permanently remove a domain and everything under it from Stalwart';

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

        $kubectl = Kubectl::forContext($context)->prefix();
        $ns = $this->mailNamespace();

        if (! $this->isMailInstalled($kubectl, $ns)) {
            $this->laraKubeError('Stalwart is not installed. Run `larakube tool:init --tool=stalwart` first.');

            return 1;
        }

        $domain = $this->flagOrPrompt(
            'domain',
            fn () => text(label: 'Which domain should be permanently removed from Stalwart?', required: true),
            'the domain to delete',
            'larakube mail:domain-delete production --domain=partner.example',
        );

        $domainRecord = null;
        foreach ($this->stalwartDomains($kubectl, $ns) ?? [] as $candidate) {
            if (($candidate['name'] ?? null) === $domain) {
                $domainRecord = $candidate;
                break;
            }
        }

        if ($domainRecord === null) {
            $this->laraKubeError("Domain '{$domain}' is not configured on this Stalwart instance.");

            return 1;
        }

        $accounts = $this->stalwartAccountsForDomain($kubectl, $ns, $domain);
        $signatures = $this->stalwartDkimSignatures($kubectl, $ns);

        if ($accounts === null || $signatures === null) {
            $this->laraKubeError('Could not connect to the Stalwart API.');

            return 1;
        }

        $signatures = array_values(array_filter($signatures, fn (array $s): bool => $s['domain'] === $domain));
        $dnsServerId = $domainRecord['dnsManagement']['dnsServerId'] ?? null;

        $this->newLine();
        $this->line("  <fg=red>⚠ This PERMANENTLY removes '{$domain}' from Stalwart on {$ns}:</>");
        $this->line('  <fg=red>  • '.count($accounts).' mailbox account(s) on this domain — ALL mail data lost</>');
        $this->line('  <fg=red>  • '.count($signatures).' DKIM signature(s) for this domain</>');
        if ($dnsServerId !== null) {
            $this->line("  <fg=red>  • the domain's own Cloudflare DNS-server credentials registered in Stalwart</>");
        }
        $this->line('  <fg=red>  • the domain object itself (TLS/DNS/DKIM automation config)</>');
        $this->newLine();
        $this->line('  <fg=yellow>This does NOT touch any live DNS records already published at Cloudflare —</>');
        $this->line('  <fg=yellow>only removes this domain\'s own objects from Stalwart\'s JMAP store.</>');
        $this->newLine();

        if (! $this->option('force')) {
            $confirm = text(label: "To confirm, type the domain name '{$domain}':", required: true);

            if ($confirm !== $domain) {
                $this->laraKubeError('Confirmation failed. Deletion aborted.');

                return 1;
            }
        }

        if ($accounts !== []) {
            $accountIds = array_column($accounts, 'id');
            $destroyedAccounts = 0;
            $this->withSpin('Deleting '.count($accountIds).' mailbox account(s)...', function () use (&$destroyedAccounts, $kubectl, $ns, $accountIds): void {
                $destroyedAccounts = $this->stalwartDestroyAccounts($kubectl, $ns, $accountIds);
            });

            if ($destroyedAccounts !== count($accountIds)) {
                $this->laraKubeError('Only '.(int) $destroyedAccounts.'/'.count($accountIds).' account(s) were destroyed. Aborting before touching DKIM/domain/DNS objects — re-run to retry.');

                return 1;
            }
        }

        if ($signatures !== []) {
            $signatureIds = array_column($signatures, 'id');
            $destroyedSignatures = 0;
            $this->withSpin('Deleting '.count($signatureIds).' DKIM signature(s)...', function () use (&$destroyedSignatures, $kubectl, $ns, $signatureIds): void {
                $destroyedSignatures = $this->stalwartDestroyDkimSignatures($kubectl, $ns, $signatureIds);
            });

            if ($destroyedSignatures !== count($signatureIds)) {
                $this->laraKubeError('Only '.(int) $destroyedSignatures.'/'.count($signatureIds).' DKIM signature(s) were destroyed. Aborting before touching the domain/DNS objects — re-run to retry.');

                return 1;
            }
        }

        $domainDestroyed = false;
        $this->withSpin("Removing the domain '{$domain}'...", function () use (&$domainDestroyed, $kubectl, $ns, $domainRecord): void {
            $domainDestroyed = $this->stalwartDestroyDomain($kubectl, $ns, $domainRecord['id']);
        });

        if (! $domainDestroyed) {
            $this->laraKubeError("Could not destroy the domain object for '{$domain}' — Stalwart's JMAP API may not support x:Domain/set destroy. Accounts and DKIM signatures ARE already removed.");

            $softDisabled = $this->stalwartSoftDisableDomain($kubectl, $ns, $domainRecord['id']);

            if ($softDisabled) {
                $this->laraKubeWarn("  Fell back to disabling '{$domain}' instead (isEnabled: false, DNS/TLS automation off) — the domain object itself still exists.");
            } else {
                $this->laraKubeError('  The soft-disable fallback also failed. The domain is still fully configured and enabled.');
            }

            return 1;
        }

        if ($dnsServerId !== null) {
            $dnsServerDestroyed = $this->stalwartDestroyDnsServer($kubectl, $ns, $dnsServerId);

            if (! $dnsServerDestroyed) {
                $this->laraKubeError("Domain removed, but its DnsServer ({$dnsServerId}) could not be destroyed — it is now orphaned (holds a Cloudflare token Stalwart no longer uses for this domain). Clean up manually.");

                return 1;
            }
        }

        $this->newLine();
        $this->info("✅ '{$domain}' fully removed from Stalwart.");
        $this->line('  Removed '.count($accounts).' mailbox(es) and '.count($signatures).' key(s) total.');
        $this->line('  Cloudflare DNS was left alone — clean that up separately.');

        return 0;
    }
}
