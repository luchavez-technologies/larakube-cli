# Implementation Plan: Realtime DNS & HTTPS Readiness Indicators + ServerSwitcher Kind & IP Row

## Context & Goal
1. Show live `DNS` and `HTTPS` readiness pills on cloud hostnames in LaraKube Desktop without truncating the URL box (`Two-Row URL Box` on App cards & Project Public Address) and without cluttering the pills with resolved IP addresses.
2. Surface each server's **IP address on a second row** (only when applicable — Managed Kubernetes & Local clusters have no dedicated static IP) alongside a **kind indicator pill** (`VPS`, `Managed`, `Local`, `External`) in `ServerSwitcher`.

## Changes
1. **`DomainStatusPills` (`desktop/resources/js/components/domain-status-pills.tsx`)**:
   - Summary detail text renders `"Checking DNS & HTTPS..."`, pending DNS/HTTPS status, or `"Public DNS & HTTPS ready"` (no resolved IP or redundant `TLS active`).
2. **`serverKindLabel` (`desktop/resources/js/lib/servers.ts`)**:
   - Classifies servers into `'Local' | 'Managed' | 'External' | 'VPS'`.
3. **`SelectMenu` (`desktop/resources/js/components/select-menu.tsx`)**:
   - Renders `option.description` as a clean monospace subtitle row beneath `option.label` inside each option button.
4. **`ServerSwitcher` (`desktop/resources/js/components/server-switcher.tsx`)**:
   - Displays each server's `VPS` / `Managed` / `Local` / `External` badge and renders `<Redactable value={candidate.ip} kind="ip" />` on row 2 when `candidate.ip` is present.
