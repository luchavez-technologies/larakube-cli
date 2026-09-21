<?php

namespace App\Traits;

use App\Enums\ClusterTool;
use Illuminate\Support\Facades\Process;

/**
 * Deletes the Zitadel OIDC app `sso:wire` registered for one tool instance,
 * and the `sso-app-*` Secret that records its ids.
 *
 * Removing a tool must do this itself: once its registry row and OIDC Secret
 * are gone, `sso:unwire` can no longer find it, and `sso:prune` only removes
 * whole projects, so the app would be orphaned for good.
 *
 * The using class provides InteractsWithSso and InteractsWithZitadelApi.
 */
trait DeregistersSsoApp
{
    protected function deregisterSsoApp(ClusterTool $tool, string $instance, string $kubectl, string $env): void
    {
        $ssoNs = ClusterTool::SSO->namespace();
        $appSecret = $this->ssoAppSecretName($tool, $instance);
        $projectId = $this->readClusterSecretKey($kubectl, $ssoNs, $appSecret, 'project-id');
        $appId = $this->readClusterSecretKey($kubectl, $ssoNs, $appSecret, 'app-id');

        // Zitadel ids are numeric; anything else means the tool was never wired.
        if (! ctype_digit((string) $projectId) || ! ctype_digit((string) $appId)) {
            return;
        }

        $ssoHost = $this->resolveSsoHostReadOnly($env, null, $kubectl);
        $pat = $this->readSsoSecret($kubectl, $ssoNs, 'machine-pat');
        $deleted = $ssoHost !== null && $pat !== null
            && $this->withSpin("Deregistering {$tool->getLabel()} from Zitadel...", fn () => $this->zitadelDeleteOidcApp($ssoHost, $pat, $projectId, $appId));

        if (! $deleted) {
            // Keep the Secret: its ids are what finds the app to delete by hand.
            $this->laraKubeWarn("Couldn't delete {$tool->getLabel()}'s Zitadel app (project {$projectId}, app {$appId}). Delete it in the Zitadel console; {$ssoNs}/{$appSecret} holds its ids.");

            return;
        }

        Process::run("{$kubectl} delete secret {$appSecret} -n {$ssoNs} --ignore-not-found");
    }
}
