# LaraKube Drive Integration Plan (`drive:init`)

This document outlines the architectural plan for adding a self-hosted Google Drive / Dropbox alternative to LaraKube. Following the dual-engine pattern established by `sheets:init` and `flow:init`, we will support two distinct engines that cater to different team needs.

## 1. Engine Selection

To match the licensing model restrictions you mentioned (like n8n, where core features are free but enterprise features are gated), we will support **ownCloud Infinite Scale (oCIS)** alongside **Nextcloud**.

*   **ownCloud Infinite Scale (oCIS) (The "Dropbox" / n8n equivalent):**
    *   **Philosophy:** A complete, cloud-native rewrite of ownCloud in Go. It abandons PHP and databases entirely for a microservice architecture. It is blisteringly fast and specifically designed to use **S3-compatible object storage (like your SeaweedFS)** as its primary backend, making it infinitely scalable.
    *   **Licensing:** Open-Core. The Community edition is 100% free and open-source (Apache 2.0) for personal and small-team use, including full S3 support out of the box. Commercial and enterprise deployments require a paid license for enterprise governance features, priority support, and compliance tools (matching the n8n model).
*   **Nextcloud (The "Google Workspace" / Windmill equivalent):**
    *   **Philosophy:** A massive, all-in-one PHP monolith collaboration suite (Drive, Docs, Chat, Calendar). While it *can* use S3, it is heavily reliant on a traditional Postgres database and local POSIX storage for best performance.
    *   **Licensing:** 100% Free and Open Source (AGPLv3). They do not paywall features; they only charge for SLA support and Enterprise deployment assistance.

---

## 2. Core Abstractions

### 2.1. Update `App\Enums\ClusterTool`
Add the new tool to the `ClusterTool` enum so it integrates natively with `tool:add`.
```php
case DRIVE = 'drive';

public function getLabel(): string
{
    return match ($this) {
        // ...
        self::DRIVE => 'Nextcloud or oCIS (Cloud Storage & Sync)',
    };
}
```

### 2.2. Update `App\Enums\SharedClusterService`
Register the service so DNS and host resolution can assign a subdomain like `drive.yourdomain.com`.
```php
case DRIVE = 'drive';
```

---

## 3. The Command (`DriveInitCommand.php`)

Create `App\Commands\Drive\DriveInitCommand` mirroring the structure of `FlowInitCommand`.

**Command Signature:**
```bash
larakube drive:init
    {--engine= : "ocis" or "nextcloud"}
    {--no-plex : Bypass the Commons (for SQLite usage)}
    {--s3-endpoint= : S3 URL for oCIS (defaults to auto-discovering SeaweedFS)}
    {--vpn-only : Restrict access to NetBird VPN}
```

**Resource Allocation:**
*   **Nextcloud:** Will require a Postgres database via `$this->allocateDatabase()` and a Redis logical index for locking via `$this->allocateCommonsRedisIndex('drive')`.
*   **oCIS:** Needs zero databases or Redis caches! It only requires an S3 bucket (SeaweedFS) and an OIDC provider (Zitadel).

---

## 4. Kubernetes Manifests (`cli/resources/views/k8s/drive/`)

### 4.1. `ocis.blade.php`
*   **Storage:** We will configure the `OCIS_DEFAULT_STORAGE_SYSTEM` to S3 and inject the SeaweedFS credentials via a Kubernetes Secret. This completely eliminates the need for giant, slow `PersistentVolumeClaims`.
*   **Env Vars:** oCIS configuration is fully declarative via environment variables. We will easily wire it to Zitadel for SSO and SeaweedFS for storage.

### 4.2. `nextcloud.blade.php`
*   **Storage:** Requires a `PersistentVolumeClaim` (e.g., `drive-storage`) for the Nextcloud `data/` directory and config, as its S3 primary storage implementation is historically much slower than POSIX.
*   **Env Vars:** Native support for external Postgres and Redis via environment variables (`POSTGRES_DB`, `REDIS_HOST`, etc.), plugging directly into the Plex Commons.

### 4.3. SMTP and SSO Wiring
*   **SMTP:** Update `ClusterTool::smtpEnv()` so `mail:wire` can automatically patch Nextcloud/oCIS with Stalwart SMTP credentials for invite emails.
*   **SSO/OIDC:** Update `ClusterTool::oidcEnv()` to support Nextcloud’s Social Login app and oCIS's native OIDC integration, pointing them both to Zitadel.

---

## 5. Teardown Logic (`--remove`)

Ensure the teardown is surgically clean to prevent data leaks:
1.  **Drop DB/Cache (Nextcloud):** Run SQL to drop the `drive` database from the Commons and release the Redis index.
2.  **Delete Resources:** Delete the deployments, services, ingresses, and secrets.
3.  *(Optional)* **State Retention:** For Nextcloud, explicitly prompt before deleting `pvc/drive-storage`. For oCIS, data lives in SeaweedFS, so we simply delete the stateless deployment pods without risking data loss.
