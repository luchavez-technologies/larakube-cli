<?php

namespace App\Contracts;

/**
 * SMTP-consumer wiring schema for a vendor that sends outbound mail.
 * mail:wire fills the schema from the Stalwart endpoint it resolves.
 */
interface HasSmtpWiring
{
    /**
     * Schema WITHOUT 'namespace' — ClusterTool injects that uniformly after
     * dispatch, since namespace() is category-level, not vendor-level.
     *
     * @return array{deployment: string, secret: string, static?: array<string, string>, vars: array<string, string>}|null
     */
    public function smtpEnv(?string $instance = null): ?array;
}
