<?php

namespace App\Commands\Sso;

use App\Exceptions\MissingFlagException;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\InteractsWithCloudflareApi;
use App\Traits\InteractsWithSsoGrants;
use App\Traits\LaraKubeOutput;
use App\Traits\RequiresFlagsWhenNonInteractive;

use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

/**
 * Onboard a partner team's own domain as a REAL Zitadel organization —
 * verified custom-domain ownership, its own RBAC claim-flattening Action,
 * and (optionally) its own admin — sharing the existing shared-tool
 * projects rather than a dedicated Zitadel install. See sso:org-grant for
 * giving that org access to a specific project.
 *
 * Deliberately a full org, not a role-scoped user in the master org: a
 * partner team is a genuinely separate tenant, and Zitadel's own
 * multi-org/domain-verification model is the native way to represent that
 * (real tenant boundary, own ORG_OWNER, own branding) rather than a
 * shared-org shortcut.
 */
class SsoOrgCommand extends Command
{
    use ConfirmsDestructiveAction, InteractsWithCloudflareApi, InteractsWithSsoGrants, LaraKubeOutput, RequiresFlagsWhenNonInteractive;

    protected $signature = 'sso:org
        {environment=local   : Environment whose Zitadel to target}
        {--context=          : Target a specific kube-context}
        {--zone=              : The partner org\'s own domain, e.g. partner.example — verified via a Cloudflare DNS TXT record}
        {--cloudflare-token=  : API token for THIS zone (scope it to this zone only)}
        {--org-name=          : Display name for the new Zitadel org (default: the zone)}
        {--admin-email=       : Creates an ORG_OWNER human user for the partner org\'s own admin}
        {--force              : Skip the confirmation prompt}';

    protected $description = 'Onboard a partner domain as its own real Zitadel organization';

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');
        $connection = $this->resolveSsoGrantConnection($env, $this->option('context'));
        if ($connection === null) {
            return 1;
        }
        [$ssoHost, $pat] = $connection;

        $zone = $this->resolveZone();
        $token = $this->resolveToken($zone);
        $orgName = (string) ($this->option('org-name') ?: $zone);
        $adminEmail = (string) $this->option('admin-email') ?: null;

        if (! $this->confirmDestructive([
            "'{$orgName}' becomes a real Zitadel organization, with '{$zone}' as its verified domain:",
            'Ownership is proven via a Cloudflare DNS TXT record written using the supplied token.',
            $adminEmail ? "An ORG_OWNER admin account is created for {$adminEmail}." : 'No admin account is created (pass --admin-email to add one).',
        ])) {
            return 0;
        }

        $orgId = null;
        $this->withSpin("Ensuring the '{$orgName}' organization exists...", function () use (&$orgId, $ssoHost, $pat, $orgName): void {
            $orgId = $this->zitadelEnsureOrg($ssoHost, $pat, $orgName);
        });

        if ($orgId === null) {
            $this->laraKubeError('Failed to create/find the organization. Check the Zitadel API connection.');

            return 1;
        }

        if (! $this->zitadelAddOrgDomain($ssoHost, $pat, $orgId, $zone)) {
            $this->laraKubeError("Failed to claim '{$zone}' as a domain on the organization.");

            return 1;
        }

        $challenge = null;
        $this->withSpin("Generating the DNS ownership challenge for {$zone}...", function () use (&$challenge, $ssoHost, $pat, $orgId, $zone): void {
            $challenge = $this->zitadelGenerateOrgDomainValidation($ssoHost, $pat, $orgId, $zone);
        });

        if ($challenge === null) {
            $this->laraKubeError('Failed to generate the domain ownership challenge.');

            return 1;
        }

        $zoneId = $this->cloudflareZoneId($zone, $token);
        if ($zoneId === null) {
            $this->laraKubeError("Could not find the '{$zone}' zone with the supplied Cloudflare token — check the token is scoped to this zone.");

            return 1;
        }

        $txtName = "_zitadel-challenge.{$zone}";
        if (! $this->cloudflareUpsertTxtRecord($zoneId, $token, $txtName, $challenge['token'])) {
            $this->laraKubeError("Failed to write the {$txtName} TXT record via the Cloudflare API.");

            return 1;
        }

        $verified = false;
        $this->withSpin('Waiting for DNS propagation and verifying domain ownership...', function () use (&$verified, $ssoHost, $pat, $orgId, $zone): void {
            $verified = $this->zitadelValidateOrgDomain($ssoHost, $pat, $orgId, $zone);
        });

        if (! $verified) {
            $this->laraKubeError("Could not verify {$zone} yet — DNS may still be propagating. Re-run this command in a minute; the TXT record and organization are already in place.");

            return 1;
        }

        if (! $this->zitadelEnsureRbacAction($ssoHost, $pat, $orgId)) {
            $this->laraKubeError("Domain verified, but the RBAC claim-flattening Action could not be installed in {$orgName}'s org — tool role grants won't reach this org's users until this is fixed. Re-run this command.");

            return 1;
        }

        if ($adminEmail !== null) {
            $userId = null;
            $this->withSpin("Creating an admin account for {$adminEmail}...", function () use (&$userId, $ssoHost, $pat, $adminEmail, $orgId): void {
                $userId = $this->zitadelCreateUser($ssoHost, $pat, $adminEmail, $adminEmail, null, $orgId);
            });

            if ($userId === null) {
                $this->laraKubeError("Domain verified, but the admin account for {$adminEmail} could not be created — check Zitadel's console.");
            } elseif (! $this->zitadelAddOrgMember($ssoHost, $pat, $orgId, $userId, ['ORG_OWNER'])) {
                $this->laraKubeError("Admin account created, but could not be made ORG_OWNER of {$orgName} — check Zitadel's console.");
            }
        }

        $this->newLine();
        $this->laraKubeInfo("✅ {$orgName} is a real Zitadel organization, with {$zone} verified.");
        $this->newLine();
        $this->line("  <fg=gray>Org:</>    <fg=blue>{$orgName}</>");
        $this->line("  <fg=gray>Domain:</> <fg=blue>{$zone}</> <fg=blue>(verified)</>");
        if ($adminEmail !== null) {
            $this->line("  <fg=gray>Admin:</>  <fg=blue>{$adminEmail}</> (log in at https://{$ssoHost})");
        }
        $this->newLine();
        $this->line('  <fg=gray>Share a project with this org:</>');
        $this->line("  <fg=blue>larakube sso:org-grant {$env} --org={$zone} --project=\"LaraKube Shared Tools\"</>");
        $this->newLine();

        return 0;
    }

    /** The partner org's own domain. Required — no default, matching dns:init/mail:domain's refusal to guess. */
    protected function resolveZone(): string
    {
        return $this->flagOrPrompt(
            'zone',
            fn () => text(
                label: 'Which domain does the partner org own?',
                placeholder: 'partner.example',
                required: true,
            ),
            'the partner org\'s domain',
            'larakube sso:org production --zone=partner.example',
        );
    }

    /** The API token for this zone, used only for the one-off DNS challenge write — never persisted. */
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
                "larakube sso:org production --zone={$zone} --cloudflare-token=…",
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
