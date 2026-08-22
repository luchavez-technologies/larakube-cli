<?php

namespace App\Http\Integrations\Zitadel\Concerns;

/**
 * x-zitadel-orgid header, or none — shared by every v1 Request that can
 * target a NON-default org (e.g. a partner org onboarded via sso:org).
 * Omitted, the caller's own (master) org is used. Every consuming Request
 * must declare its own `?string $orgId` constructor property — this trait
 * reads it directly off $this.
 */
trait HasOrgHeader
{
    protected function defaultHeaders(): array
    {
        return $this->orgId !== null ? ['x-zitadel-orgid' => $this->orgId] : [];
    }
}
