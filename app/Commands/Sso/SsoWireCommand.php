<?php

namespace App\Commands\Sso;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\ClusterTool;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithSso;
use App\Traits\InteractsWithZitadelApi;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;

class SsoWireCommand extends Command
{
    use DeploysClusterTool, InteractsWithClusterContext, InteractsWithSso, InteractsWithZitadelApi, LaraKubeOutput;

    protected $signature = 'sso:wire
        {environment=local : Environment whose deployment to wire}
        {--tool= : The tool to wire to Zitadel}
        {--context= : Target a specific kube-context}
        {--project= : Zitadel project name to register the OIDC app under (default: LaraKube Shared Tools)}
        {--admin-email= : Email of the user to grant the tool\'s admin role to (tools with ssoAdminRoles(), e.g. drive)}
        {--sso-only : Enforce SSO-only login and disable local password authentication}
        {--remove   : Deregister the OIDC app and unset the tool\'s SSO env vars}';

    protected $description = 'Register a tool as an OIDC client in Zitadel and wire its login to SSO';

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');
        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;

        $context = $this->resolveToolContext($env, $this->option('context'));
        $kubectl = $this->ssoKubectl($context);
        $ssoNs = $this->ssoNamespace();

        $tool = $this->resolveTool($kubectl);
        if ($tool === null) {
            return 1;
        }

        if (! $tool->hasSsoWire()) {
            $this->laraKubeError("'{$tool->value}' can't be wired to SSO.");

            return 1;
        }

        $schema = $tool->oidcEnv();
        if ($schema === null) {
            return 1;
        }

        if (! $this->isSsoInstalled($kubectl, $ssoNs)) {
            $this->laraKubeError('Zitadel is not installed. Run `larakube sso:init` first.');

            return 1;
        }

        if (! $this->deploymentExists($kubectl, $schema['namespace'], $schema['deployment'])) {
            $this->laraKubeError("{$tool->getLabel()} is not installed.");

            return 1;
        }

        $ssoHost = $this->resolveSsoHostReadOnly($env, $config);
        $toolHost = $this->targetHost($tool, $env, $config);

        if ($ssoHost === null || $toolHost === null) {
            $this->laraKubeError("No host is configured for {$tool->getLabel()} or Zitadel in '{$env}' — run their :init commands first.");

            return 1;
        }

        $pat = $this->readSsoSecret($kubectl, $ssoNs, 'machine-pat');
        if ($pat === null) {
            $this->laraKubeError('Could not reach Zitadel\'s automation credentials — re-run `larakube sso:init` to recapture them.');

            return 1;
        }

        return $this->option('remove')
            ? $this->unwire($tool, $schema, $kubectl, $ssoNs, $ssoHost, $pat)
            : $this->wire($tool, $schema, $kubectl, $ssoNs, $ssoHost, $toolHost, $pat, $env);
    }

    protected function wire(ClusterTool $tool, array $schema, string $kubectl, string $ssoNs, string $ssoHost, string $toolHost, string $pat, string $env): int
    {
        $appSecret = "sso-app-{$tool->value}";
        $clientId = $this->readNamedSecret($kubectl, $ssoNs, $appSecret, 'client-id');
        $clientSecret = $this->readNamedSecret($kubectl, $ssoNs, $appSecret, 'client-secret');

        $projectName = (string) ($this->option('project') ?: 'LaraKube Shared Tools');
        $projectId = $this->zitadelEnsureProject($ssoHost, $pat, $projectName);

        // Open-to-org tools with ssoAdminRoles() (e.g. drive's ocisAdmin)
        // ship PROXY_ROLE_ASSIGNMENT_DRIVER=oidc, which re-asserts the role
        // from the `ocisRoles` claim on every login and DENIES a token with
        // no such claim. Installing the claim-flattening Action + the admin
        // role is therefore NOT optional — it's the safety precondition that
        // makes driver=oidc safe. Without it, sso:wire would turn off the
        // only thing that keeps open-to-org logins working.
        if ($tool->ssoAdminRoles() !== []) {
            if ($projectId === null || ! $this->ensureSsoAdminGating($ssoHost, $pat, $projectId, $tool)) {
                $this->laraKubeError(
                    "Could not set up admin-role claims for {$tool->getLabel()} — the claim-flattening Action or ".
                    'project role failed to apply. PROXY_ROLE_ASSIGNMENT_DRIVER=oidc depends on the ocisRoles '.
                    'claim, so this stops here rather than deny every login.',
                );

                return 1;
            }

            $adminEmail = trim((string) ($this->option('admin-email') ?: ''));
            if ($adminEmail !== '' && ! $this->ensureSsoAdminGrant($ssoHost, $pat, $projectId, $tool, $adminEmail)) {
                return 1;
            }
        }

        // Zitadel's app endpoint keys on the APP id, not the client id — they're
        // different values. Looking it up by client id always 404s, which made
        // the reuse branch below dead code: every re-run silently re-registered
        // and rotated the client secret, despite the "pass --remove to rotate"
        // message promising the opposite.
        $appId = $this->readNamedSecret($kubectl, $ssoNs, $appSecret, 'app-id');

        $appExistsInZitadel = false;
        if ($appId !== null && $projectId !== null) {
            $checkApp = Http::withToken($pat)->timeout(10)->get("https://{$ssoHost}/management/v1/projects/{$projectId}/apps/{$appId}");
            $appExistsInZitadel = $checkApp->successful();
        }

        if ($clientId === null || $clientSecret === null || ! $appExistsInZitadel) {
            $redirectUris = $tool->oidcRedirectUris($toolHost);

            $registered = null;
            $this->withSpin("Registering {$tool->getLabel()} as an OIDC client in Zitadel...", function () use (&$registered, $ssoHost, $pat, $projectName, $tool, $redirectUris) {
                $projectId = $this->zitadelEnsureProject($ssoHost, $pat, $projectName);
                if ($projectId === null) {
                    return;
                }

                $app = $this->zitadelCreateOidcApp($ssoHost, $pat, $projectId, $tool->productName(), $redirectUris);
                if ($app === null) {
                    return;
                }

                $registered = array_merge($app, ['projectId' => $projectId]);
            });

            if ($registered === null) {
                $this->laraKubeError("Could not register {$tool->getLabel()} in Zitadel — check the automation credentials and Zitadel's own logs.");

                return 1;
            }

            Process::run(
                "{$kubectl} create secret generic {$appSecret} -n {$ssoNs} "
                .'--from-literal=project-id='.escapeshellarg($registered['projectId']).' '
                .'--from-literal=app-id='.escapeshellarg($registered['appId']).' '
                .'--from-literal=client-id='.escapeshellarg($registered['clientId']).' '
                .'--from-literal=client-secret='.escapeshellarg($registered['clientSecret']).' '
                ."--dry-run=client -o yaml | {$kubectl} apply -f -",
            );

            $clientId = $registered['clientId'];
            $clientSecret = $registered['clientSecret'];
        } else {
            $this->laraKubeInfo("Reusing {$tool->getLabel()}'s existing Zitadel OIDC client (pass --remove then re-wire to rotate it).");
        }

        $logical = [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'auth_url' => "https://{$ssoHost}/oauth/v2/authorize",
            'token_url' => "https://{$ssoHost}/oauth/v2/token",
            'userinfo_url' => "https://{$ssoHost}/oidc/v1/userinfo",
            'issuer' => "https://{$ssoHost}",
        ];

        $ok = $this->applyToolEnv($kubectl, $schema, $logical);

        if (! $ok) {
            $this->laraKubeError("Failed to wire {$tool->getLabel()} to Zitadel.");

            return 1;
        }

        $this->laraKubeInfo("✅ {$tool->getLabel()} is wired to Zitadel SSO.");
        $this->newLine();

        if ($this->option('sso-only')) {
            $this->line("  <fg=yellow>🔒 SSO-only mode enabled — local password logins are disabled on {$tool->getLabel()}.</>");
            $this->line("  <fg=gray>To restore local password login, re-run:</> <fg=blue>larakube sso:wire {$env} --tool={$tool->value}</>");
            $this->newLine();
        }

        $this->line("  <fg=gray>Redirect URI registered:</> <fg=blue>https://{$toolHost}{$schema['redirect_path']}</>");
        $this->newLine();

        return 0;
    }

    /**
     * Safety precondition for open-to-org tools that ship
     * PROXY_ROLE_ASSIGNMENT_DRIVER=oidc: the org-wide flattenOcisRoles
     * Action (always-emit ocisRoles claim) plus every ssoAdminRoles() role
     * on the tool's own project. Unlike role-gated tools this does NOT deny
     * anyone login — the Action's ocisUser fallback keeps every org member
     * in — but without it driver=oidc would lock everyone out, so a failure
     * must stop the wire before the statics are applied.
     *
     * The tool's project must also carry projectRoleAssertion: Zitadel only
     * populates the Action's ctx.v1.user.grants for projects in the role
     * audience, and without the flag the grants resolve empty at runtime —
     * the Action falls back to ocisUser and every grantee silently lands on
     * the plain "user" role (the live "no + New Space button" bug on Drive,
     * 2026-08-02).
     *
     * Prints the admin roles and the sso:grant incantation for the operator.
     */
    protected function ensureSsoAdminGating(string $ssoHost, string $pat, string $projectId, ClusterTool $tool): bool
    {
        $ok = true;
        $this->withSpin("Configuring admin-role claims for {$tool->getLabel()}...", function () use ($ssoHost, $pat, $projectId, $tool, &$ok) {
            $ok = $this->zitadelEnsureSsoAdminProjectSettings($ssoHost, $pat, $projectId);

            if ($ok) {
                $ok = $this->zitadelEnsureOcisRolesAction($ssoHost, $pat);
            }

            foreach ($tool->ssoAdminRoles() as $roleKey => $label) {
                if (! $ok) {
                    break;
                }
                $ok = $this->zitadelEnsureProjectRole($ssoHost, $pat, $projectId, $roleKey, $label);
            }
        });

        if (! $ok) {
            return false;
        }

        $this->newLine();
        $this->line('  <fg=blue>Open-to-org tool — every org member can log in. Admin roles:</>');
        foreach ($tool->ssoAdminRoles() as $roleKey => $label) {
            $this->line("    <fg=blue>{$roleKey}</> — {$label}");
        }
        $this->line("  <fg=gray>larakube sso:grant --tool={$tool->value} --role=<role> --email=<user> — or re-wire with --admin-email=</>");
        $this->newLine();

        return true;
    }

    /**
     * Grant --admin-email every ssoAdminRoles() role on the tool's own
     * project. Hard-fails (instead of warning and continuing) when the
     * email resolves to no Zitadel user — the whole point of --admin-email
     * is that this person IS the admin, so a typo'd address must surface.
     */
    protected function ensureSsoAdminGrant(string $ssoHost, string $pat, string $projectId, ClusterTool $tool, string $adminEmail): bool
    {
        $userId = $this->zitadelFindUserByEmail($ssoHost, $pat, $adminEmail);
        if ($userId === null) {
            $this->laraKubeError("No Zitadel user found for '{$adminEmail}' — create the user in Zitadel or fix --admin-email, then re-run.");

            return false;
        }

        foreach (array_keys($tool->ssoAdminRoles()) as $roleKey) {
            if (! $this->zitadelGrantRole($ssoHost, $pat, $userId, $projectId, $roleKey)) {
                $this->laraKubeError("Failed to grant '{$roleKey}' to {$adminEmail}.");

                return false;
            }
        }

        $granted = implode(', ', array_map(fn (string $k) => "'{$k}'", array_keys($tool->ssoAdminRoles())));
        $this->laraKubeInfo("✅ Granted {$granted} to {$adminEmail}.");

        return true;
    }

    protected function unwire(ClusterTool $tool, array $schema, string $kubectl, string $ssoNs, string $ssoHost, string $pat): int
    {
        $appSecret = "sso-app-{$tool->value}";
        $projectId = $this->readNamedSecret($kubectl, $ssoNs, $appSecret, 'project-id');
        $appId = $this->readNamedSecret($kubectl, $ssoNs, $appSecret, 'app-id');

        if ($projectId !== null && $appId !== null) {
            $this->withSpin("Deregistering {$tool->getLabel()} from Zitadel...", fn () => $this->zitadelDeleteOidcApp($ssoHost, $pat, $projectId, $appId));
        }

        Process::run("{$kubectl} delete secret {$appSecret} -n {$ssoNs} --ignore-not-found");

        $unset = array_values($schema['vars']);
        if (! empty($schema['static'])) {
            $unset = array_merge($unset, array_keys($schema['static']));
        }
        if (! empty($schema['sso_only_vars'])) {
            $unset = array_merge($unset, array_keys($schema['sso_only_vars']));
        }

        $pairs = implode(' ', array_map(fn (string $key) => $key.'-', $unset));

        $ok = true;
        $this->withSpin("Unwiring {$tool->getLabel()} from Zitadel...", function () use ($kubectl, $schema, $pairs, &$ok) {
            $ok = Process::run("{$kubectl} set env deployment/{$schema['deployment']} -n {$schema['namespace']} {$pairs}")->successful();
            if ($ok) {
                Process::run("{$kubectl} rollout restart deployment/{$schema['deployment']} -n {$schema['namespace']}");
            }
        });

        if (! $ok) {
            $this->laraKubeError("Failed to unwire {$tool->getLabel()} from Zitadel.");

            return 1;
        }

        $this->laraKubeInfo("✅ {$tool->getLabel()} no longer uses Zitadel SSO.");

        return 0;
    }

    /**
     * @param  array{deployment: string, namespace: string, secret: string, static?: array<string, string>, vars: array<string, string>}  $schema
     * @param  array<string, string>  $logical
     */
    protected function applyToolEnv(string $kubectl, array $schema, array $logical): bool
    {
        $literals = '';
        foreach ($schema['vars'] as $key => $envName) {
            if (isset($logical[$key])) {
                $literals .= '--from-literal='.$envName.'='.escapeshellarg($logical[$key]).' ';
            }
        }

        $secret = $schema['secret'];
        $deployment = $schema['deployment'];
        $ns = $schema['namespace'];

        $ok = true;
        $this->withSpin("Wiring {$deployment}...", function () use ($kubectl, $ns, $secret, $literals, $deployment, $schema, &$ok) {
            Process::run(
                "{$kubectl} create secret generic {$secret} -n {$ns} {$literals}--dry-run=client -o yaml | {$kubectl} apply -f -",
            );

            $set = Process::run("{$kubectl} set env deployment/{$deployment} --from=secret/{$secret} -n {$ns}");
            $ok = $set->successful();

            $staticVars = $schema['static'] ?? [];
            $unsetPairs = '';

            if ($this->option('sso-only') && ! empty($schema['sso_only_vars'])) {
                $staticVars = array_merge($staticVars, $schema['sso_only_vars']);
            } elseif (! empty($schema['sso_only_vars'])) {
                foreach ($schema['sso_only_vars'] as $k => $v) {
                    $unsetPairs .= ' '.$k.'-';
                }
            }

            if ($ok && (! empty($staticVars) || $unsetPairs !== '')) {
                $pairs = '';
                foreach ($staticVars as $k => $v) {
                    $pairs .= ' '.$k.'='.escapeshellarg($v);
                }
                $pairs .= $unsetPairs;
                $ok = Process::run("{$kubectl} set env deployment/{$deployment} -n {$ns}{$pairs}")->successful();
            }

            if ($ok) {
                Process::run("{$kubectl} rollout restart deployment/{$deployment} -n {$ns}");
            }
        });

        return $ok;
    }

    protected function targetHost(ClusterTool $tool, string $env, ?ConfigData $config): ?string
    {
        // Every OIDC-capable tool resolves its host the same read-only way its
        // own resolve*HostReadOnly() does — through its SharedClusterService.
        // Keying off $tool->service() means a tool becomes wireable the moment
        // it has an oidcEnv() schema, with no per-tool case to remember here.
        $service = $tool->service();
        if ($service === null) {
            return null;
        }

        if ($env === 'local') {
            return $service->hostFor(GlobalConfigData::load()->getLocalTld());
        }

        return $config?->getEnvironment($env)?->hosts[$service->value] ?? null;
    }

    protected function resolveTool(?string $kubectl = null): ?ClusterTool
    {
        $slug = (string) ($this->option('tool') ?: '');
        if ($slug !== '') {
            $tool = ClusterTool::tryFrom($slug);
            if ($tool === null) {
                $this->laraKubeError("Unknown tool '{$slug}'.");

                return null;
            }

            return $tool;
        }

        $capable = array_values(array_filter(ClusterTool::cases(), fn (ClusterTool $t) => $t->hasSsoWire()));
        $installed = $kubectl !== null
            ? array_values(array_filter($capable, function (ClusterTool $t) use ($kubectl) {
                $schema = $t->oidcEnv();

                return $schema !== null && $this->deploymentExists($kubectl, $schema['namespace'], $schema['deployment']);
            }))
            : $capable;

        if ($installed === []) {
            $this->laraKubeError('No OIDC-capable tools (e.g. Vaultwarden, Grafana) are currently installed on this cluster.');

            return null;
        }

        $options = [];
        foreach ($installed as $t) {
            $options[$t->value] = $t->getLabel();
        }

        return ClusterTool::from(select(
            label: $this->option('remove') ? 'Unwire SSO from which tool?' : 'Wire which tool to Zitadel SSO?',
            options: $options,
            scroll: count($options),
        ));
    }

    protected function deploymentExists(string $kubectl, string $ns, string $deployment): bool
    {
        return trim(Process::run(
            "{$kubectl} get deployment {$deployment} -n {$ns} --no-headers --ignore-not-found",
        )->output()) !== '';
    }
}
