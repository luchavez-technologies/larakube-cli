<?php

namespace App\Traits;

use App\Enums\ClusterTool;
use Illuminate\Support\Facades\Process;

/**
 * The single place Penpot's PENPOT_FLAGS gets computed. design:init,
 * sso:wire, and mail:wire each used to independently union whatever string
 * was already stored with their own addition — a mechanism that can only
 * ever GROW the flag set, never correct it. That's what let `enable-mcp`
 * survive indefinitely once written, even after the code that added it was
 * reverted: nothing ever recomputed the value from scratch, only appended to
 * whatever history happened to already be there. Confirmed live 2026-08-10 —
 * took down design.luchtech.dev. See
 * docs/decisions/0013-design-init-idempotent-flags.md.
 *
 * This computes PENPOT_FLAGS fresh every time from verifiable truth — real
 * credentials, not a copy of a string a past run happened to write — so a
 * flag that's no longer warranted drops out on the next reconcile instead of
 * persisting forever.
 *
 * Depends on ReadsClusterSecrets::readClusterSecretKey() being available on
 * the consuming class — every current user (DesignInitCommand via
 * InteractsWithDesign, SsoWireCommand, MailWireCommand) already has it.
 */
trait ReconcilesPenpotFlags
{
    /**
     * @param  bool|null  $ssoOnly  Explicit true/false when the caller knows
     *                              for certain (sso:wire's own --sso-only option this run); null to
     *                              infer from whatever the live pod currently has, so an unrelated
     *                              design:init/mail:wire run doesn't silently revert a previously
     *                              enabled --sso-only mode it knows nothing about.
     */
    protected function resolveDesignPenpotFlags(
        string $kubectl,
        string $ns,
        string $oidcSecretName,
        string $smtpSecretName,
        ?bool $ssoOnly,
        string $backendDeployment,
    ): string {
        $flags = ClusterTool::DESIGN->baselineFlags();

        $oidcWired = trim((string) ($this->readClusterSecretKey($kubectl, $ns, $oidcSecretName, 'PENPOT_OIDC_CLIENT_ID') ?? '')) !== '';
        if ($oidcWired) {
            $flags[] = 'enable-login-with-oidc';

            $ssoOnlyActive = $ssoOnly ?? $this->designPenpotFlagIsLive($kubectl, $ns, $backendDeployment, $oidcSecretName, 'disable-login-with-password');
            if ($ssoOnlyActive) {
                $flags[] = 'disable-registration';
                $flags[] = 'disable-login-with-password';
            }
        }

        $smtpWired = trim((string) ($this->readClusterSecretKey($kubectl, $ns, $smtpSecretName, 'PENPOT_SMTP_HOST') ?? '')) !== '';
        if ($smtpWired) {
            $flags[] = 'enable-smtp';
        }

        $flags = array_values(array_unique($flags));
        sort($flags);

        return implode(' ', $flags);
    }

    /**
     * Whether $flag is present in the pod's EFFECTIVE PENPOT_FLAGS right
     * now — read from the live Deployment's literal env value if a past
     * `kubectl set env` put one there, falling back to the Secret's key
     * otherwise. Past wire commands have left it in both shapes depending on
     * which ran last, so a single-shape read is not reliable.
     */
    protected function designPenpotFlagIsLive(string $kubectl, string $ns, string $deployment, string $oidcSecretName, string $flag): bool
    {
        $literal = trim(Process::run(
            "{$kubectl} get deployment {$deployment} -n {$ns} -o jsonpath='{.spec.template.spec.containers[0].env[?(@.name==\"PENPOT_FLAGS\")].value}' --ignore-not-found",
        )->output());

        $current = $literal !== '' ? $literal : ($this->readClusterSecretKey($kubectl, $ns, $oidcSecretName, 'PENPOT_FLAGS') ?? '');

        return in_array($flag, explode(' ', $current), true);
    }

    /**
     * Write the reconciled value to the Secret — the ONLY place it's ever
     * written — then `kubectl rollout restart` any deployment whose pod
     * needs to pick it up right now, rather than a literal
     * `kubectl set env ... PENPOT_FLAGS=<value>`. See
     * docs/decisions/0018-wire-commands-never-literal-env.md: a literal
     * write desyncs `kubectl apply`'s bookkeeping and permanently breaks
     * every future `design:init` re-apply, which is worse than the problem
     * it solved. A rollout restart delivers the same "reaches the pod now"
     * guarantee without ever touching the env array's shape — the
     * Deployment template's optional `secretKeyRef` for PENPOT_FLAGS
     * (design:init's own base manifest) picks up the new Secret value on
     * the restart.
     *
     * Idempotent by construction — a no-op (no restart) when the value is
     * unchanged from what's already in the Secret, and only rolls the
     * (Recreate-strategy, real-downtime) Deployment when it actually
     * changes. Also refreshes the Secret's own copy so a from-scratch
     * install that hasn't had any :wire command run yet still gets a sane
     * value via the Deployment template's optional secretKeyRef fallback.
     */
    protected function applyDesignPenpotFlags(string $kubectl, string $ns, string $oidcSecretName, string $value, string ...$deployments): void
    {
        $current = $this->readClusterSecretKey($kubectl, $ns, $oidcSecretName, 'PENPOT_FLAGS');
        $changed = $current !== $value;

        $secretExists = trim(Process::run("{$kubectl} get secret {$oidcSecretName} -n {$ns} --ignore-not-found")->output()) !== '';
        if ($secretExists) {
            Process::run(
                "{$kubectl} patch secret {$oidcSecretName} -n {$ns} --type=merge -p="
                .escapeshellarg(json_encode(['data' => ['PENPOT_FLAGS' => base64_encode($value)]])),
            );
        } else {
            Process::run(
                "{$kubectl} create secret generic {$oidcSecretName} -n {$ns} "
                .'--from-literal=PENPOT_FLAGS='.escapeshellarg($value).' '
                ."--dry-run=client -o yaml | {$kubectl} apply -f -",
            );
        }

        if (! $changed) {
            return;
        }

        foreach ($deployments as $deployment) {
            if (trim(Process::run("{$kubectl} get deployment {$deployment} -n {$ns} --ignore-not-found")->output()) !== '') {
                Process::run("{$kubectl} rollout restart deployment/{$deployment} -n {$ns}");
            }
        }
    }
}
