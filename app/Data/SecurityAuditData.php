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
        /**
         * Per-gate switches, all on by default. `skip` remains the master off
         * switch and still wins over each of these.
         *
         * These exist because `skip` is all-or-nothing: it wraps the entire
         * audit phase, so turning off one gate used to cost you dependency
         * auditing, SAST, Trivy AND the test run. That bites when a single
         * tool is unavailable for reasons unrelated to your code — Gitleaks'
         * GitHub Action, for instance, requires a paid licence on
         * organisation-owned repos and hard-fails without one.
         */
        public bool $gitleaks = true,
        public bool $semgrep = true,
        public bool $dependencyAudit = true,
        public bool $trivy = true,
    ) {}

    /** Whether the secret scan runs (master switch folded in). */
    public function runsGitleaks(): bool
    {
        return ! $this->skip && $this->gitleaks;
    }

    /** Whether the SAST gate runs. */
    public function runsSemgrep(): bool
    {
        return ! $this->skip && $this->semgrep;
    }

    /** Whether the Composer/NPM advisory audit runs. */
    public function runsDependencyAudit(): bool
    {
        return ! $this->skip && $this->dependencyAudit;
    }

    /** Whether either Trivy scan (filesystem, image) runs. */
    public function runsTrivy(): bool
    {
        return ! $this->skip && $this->trivy;
    }

    /** Whether the application test suite runs in the audit phase. */
    public function runsTests(): bool
    {
        return ! $this->skip && $this->withTests;
    }

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
