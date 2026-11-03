<?php

namespace App\Commands\Sso;

use App\Exceptions\MissingFlagException;
use App\Traits\InteractsWithMail;
use App\Traits\InteractsWithSsoGrants;
use App\Traits\InteractsWithStalwartApi;
use App\Traits\LaraKubeOutput;
use App\Traits\RequiresFlagsWhenNonInteractive;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

/**
 * Offboard a partner organization (created via sso:org) from Zitadel SSO.
 *
 * Refuses to delete the instance's primary/default organization (first org
 * returned by Zitadel) so operators can never accidentally wipe their own
 * root tenant. Optionally cascades offboarding to Stalwart Mail (--with-mail)
 * when the partner organization's domain also hosts mailboxes on the cluster.
 */
class SsoOrgDeleteCommand extends Command
{
    use InteractsWithMail, InteractsWithSsoGrants, InteractsWithStalwartApi, LaraKubeOutput, RequiresFlagsWhenNonInteractive;

    protected $signature = 'sso:org-delete
        {environment=local : Environment whose Zitadel to target}
        {--context=        : Target a specific kube-context}
        {--org=            : Partner organization name or ID to permanently delete}
        {--with-mail       : Also remove the matching domain and mailboxes from Stalwart Mail if present}
        {--force           : Skip typing the organization name confirmation}';

    protected $description = 'Permanently delete a partner organization from Zitadel SSO (and optionally its Stalwart mail domain)';

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');
        $explicitContext = $this->option('context') ? (string) $this->option('context') : null;
        $connection = $this->resolveSsoGrantConnection($env, $explicitContext);
        if ($connection === null) {
            return 1;
        }
        [$ssoHost, $pat, $kubectl] = $connection;

        $orgs = $this->zitadelListOrgs($ssoHost, $pat);
        if ($orgs === []) {
            $this->laraKubeError('Could not list Zitadel organizations — check the Zitadel API connection.');

            return 1;
        }

        $primaryOrgId = (string) array_key_first($orgs);
        $primaryOrgName = $orgs[$primaryOrgId];

        $partnerOrgs = $orgs;
        unset($partnerOrgs[$primaryOrgId]);

        $orgInput = trim((string) ($this->option('org') ?? ''));

        if ($orgInput !== '') {
            if ($orgInput === $primaryOrgId || strcasecmp($orgInput, $primaryOrgName) === 0) {
                $this->laraKubeError("Cannot delete the primary organization '{$primaryOrgName}' ({$primaryOrgId}).");

                return 1;
            }

            $targetOrgId = null;
            $targetOrgName = null;

            if (isset($partnerOrgs[$orgInput])) {
                $targetOrgId = $orgInput;
                $targetOrgName = $partnerOrgs[$orgInput];
            } else {
                foreach ($partnerOrgs as $id => $name) {
                    if (strcasecmp($name, $orgInput) === 0) {
                        $targetOrgId = (string) $id;
                        $targetOrgName = $name;
                        break;
                    }
                }
            }

            if ($targetOrgId === null || $targetOrgName === null) {
                if ($this->option('with-mail') && $this->hasStalwartDomain($kubectl, $orgInput)) {
                    $this->laraKubeInfo("Organization '{$orgInput}' is already absent from Zitadel; proceeding to remove its Mail domain...");

                    return $this->deleteMailDomain($env, $explicitContext, $orgInput);
                }

                $this->laraKubeError("No partner organization matching '{$orgInput}' was found in Zitadel.");

                return 1;
            }
        } else {
            if ($partnerOrgs === []) {
                $this->laraKubeInfo("No partner organizations exist beyond the primary organization '{$primaryOrgName}'.");

                return 0;
            }

            if ($this->cannotPrompt()) {
                throw new MissingFlagException(
                    'org',
                    'the partner organization to delete',
                    'larakube sso:org-delete production --org=partner.example --force',
                );
            }

            $targetOrgId = (string) select(
                label: 'Which partner organization should be permanently deleted?',
                options: $partnerOrgs,
                default: array_key_first($partnerOrgs),
            );
            $targetOrgName = $partnerOrgs[$targetOrgId];
        }

        $this->newLine();
        $this->line("  <fg=red>⚠ This PERMANENTLY deletes the partner organization '{$targetOrgName}' ({$targetOrgId}) from Zitadel:</>");
        $this->line('  <fg=red>  • All human and machine users belonging to this organization</>');
        $this->line('  <fg=red>  • Verified custom domain claims and RBAC claim-flattening Actions</>');
        $this->line('  <fg=red>  • All cross-organization Project Grants and role assignments</>');
        if ($this->option('with-mail')) {
            $this->line("  <fg=red>  • Matching Stalwart Mail domain '{$targetOrgName}' and all its mailboxes (--with-mail)</>");
        }
        $this->newLine();

        if (! $this->option('force')) {
            $confirm = text(label: "To confirm, type the organization name '{$targetOrgName}':", required: true);
            if ($confirm !== $targetOrgName) {
                $this->laraKubeError('Confirmation failed. Deletion aborted.');

                return 1;
            }
        }

        $deleted = false;
        $this->withSpin("Deleting Zitadel organization '{$targetOrgName}'...", function () use (&$deleted, $ssoHost, $pat, $targetOrgId): void {
            $deleted = $this->zitadelDeleteOrg($ssoHost, $pat, $targetOrgId);
        });

        if (! $deleted) {
            $this->laraKubeError("Failed to delete organization '{$targetOrgName}' ({$targetOrgId}) from Zitadel.");

            return 1;
        }

        $this->newLine();
        $this->laraKubeInfo("✅ Partner organization '{$targetOrgName}' ({$targetOrgId}) removed from Zitadel SSO.");

        $withMail = (bool) $this->option('with-mail');
        if (! $withMail && ! $this->cannotPrompt() && ! $this->option('force') && $this->hasStalwartDomain($kubectl, $targetOrgName)) {
            $withMail = confirm(
                label: "Domain '{$targetOrgName}' is also configured on Stalwart Mail. Remove it and its mailboxes from Mail too?",
                default: false,
            );
        }

        if ($withMail) {
            if ($this->hasStalwartDomain($kubectl, $targetOrgName)) {
                return $this->deleteMailDomain($env, $explicitContext, $targetOrgName);
            }

            $this->line("  <fg=gray>Mail domain '{$targetOrgName}' is not configured on Stalwart — skipped Mail cleanup.</>");
        }

        return 0;
    }

    protected function hasStalwartDomain(string $kubectl, string $domain): bool
    {
        $ns = $this->mailNamespace();
        if (! $this->isMailInstalled($kubectl, $ns)) {
            return false;
        }

        foreach ($this->stalwartDomains($kubectl, $ns) ?? [] as $candidate) {
            if (isset($candidate['name']) && strcasecmp((string) $candidate['name'], $domain) === 0) {
                return true;
            }
        }

        return false;
    }

    protected function deleteMailDomain(string $env, ?string $context, string $domain): int
    {
        $args = [
            'environment' => $env,
            '--domain' => $domain,
            '--force' => true,
        ];
        if ($context !== null && $context !== '') {
            $args['--context'] = $context;
        }

        return $this->call('mail:domain-delete', $args);
    }
}
