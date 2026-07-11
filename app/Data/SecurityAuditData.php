<?php

namespace App\Data;

use Spatie\LaravelData\Data;

/**
 * Security audit configuration for CI/CD pipeline generation.
 * Lives inside EnvironmentData::$securityAudit (nullable).
 *
 * Controls whether the generated GitHub Actions / GitLab CI workflow
 * includes automated security gates (Gitleaks, Semgrep, Composer/NPM
 * audit, Trivy) and how strict those gates are.
 *
 * Defaults: audit ON, strict OFF (CRITICAL-only), tests OFF.
 */
class SecurityAuditData extends Data
{
    public function __construct(
        /**
         * Skip all audit steps — emit the lean build-push-deploy
         * pipeline without any security gates.
         */
        public bool $skip = false,
        /**
         * Also fail on HIGH severity (not just CRITICAL). Applies to
         * Composer/NPM audit, Semgrep, and Trivy image scan.
         */
        public bool $strict = false,
        /**
         * Include the PHPUnit/Pest test suite in the audit phase.
         * Off by default — deploy pipelines are not the canonical
         * test runner; a PR-triggered CI workflow is the better home.
         */
        public bool $withTests = false,
    ) {}

    /**
     * The Trivy/Semgrep severity string: CRITICAL-only by default,
     * CRITICAL,HIGH when --strict.
     */
    public function failOnSeverity(): string
    {
        return $this->strict ? 'CRITICAL,HIGH' : 'CRITICAL';
    }

    /**
     * The npm audit --audit-level value: 'critical' by default,
     * 'high' when --strict.
     */
    public function auditLevel(): string
    {
        return $this->strict ? 'high' : 'critical';
    }
}
