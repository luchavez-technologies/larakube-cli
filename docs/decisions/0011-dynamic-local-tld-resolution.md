# 0011 — Local TLD is dynamic; hardcoding `.dev.test` is forbidden

**Status:** Accepted (2026-08-08) — convention / invariant

## Context

LaraKube allows operators to configure their local development top-level domain (TLD) globally via `larakube config:tld` or per-project. While `dev.test` is the standard default local TLD, operators may use custom TLDs (such as `test`, `internal.test`, or `local.test`).

A hardcoded string like `.dev.test` in host generation or manifest generation creates subtle bugs: if an operator changes their local TLD, manifest generators and Ingress rules continue generating `.dev.test` hostnames, breaking local routing.

This invariant was documented after an audit revealed hardcoded `.dev.test` fallbacks in manifest generation.

## Decision

1. **Never hardcode `.dev.test` string literals in application or manifest logic.**
2. Always resolve the active local TLD dynamically using `GlobalConfigData::load()->getLocalTld()` or `$config->getLocalTld()`.
3. When constructing default local hostnames, derive them via:

```php
$tld = $config->getLocalTld() ?? GlobalConfigData::load()->getLocalTld();
$host = $config->getHost('local') ?? "{$config->getId()}.{$tld}";
```

## Consequences

- Local ingress routes and asset URLs remain fully aligned when an operator changes their system TLD via `config:tld`.
- Test suites stay deterministic and support multi-TLD testing.
