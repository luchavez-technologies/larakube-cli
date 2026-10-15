# Alternate SSH Port (Port 22 Firewall Fallback) — Desktop Implementation Plan

## Context & Motivation

During a workshop on a school campus, outbound traffic on **port 22** was blocked by the campus firewall. While cloud VPS creation succeeded via cloud API calls, all subsequent orchestration steps requiring SSH (k3s installation, server hardening, `dashboard:trust`, image sideloading, dev box shells) timed out and failed.

While Claude scoped the CLI infrastructure changes in `cli/plans/active/ssh-alternate-port-fallback.md`, this plan details how **LaraKube Desktop** surfaces, validates, and manages alternate SSH ports across server creation, dev box creation, remote execution, and connection details.

---

## Port Collision & Safety Matrix

On single-node VPS installations (DigitalOcean Droplet, Hetzner Cloud, AWS EC2, GCP Compute Engine), host ports are directly bound. To prevent catastrophic service outages:

| Port | Service | Status for Host SSH | Rationale |
| :--- | :--- | :--- | :--- |
| **22** | Default SSH | **Default** | Standard port; used when not blocked. |
| **80** | Traefik HTTP | **FORBIDDEN** | Collides with web edge ingress. |
| **443** | Traefik HTTPS (`websecure`) | **FORBIDDEN** | Collides with web edge SSL/TLS ingress (`hostPort: 443`). |
| **2222** | Forgejo Git SSH | **FORBIDDEN** | Collides with Forgejo git clone listener on single-node VPS. |
| **8443** | Secondary HTTPS | **RECOMMENDED ALTERNATE** | Rarely blocked by campus/school firewalls; no hostPort collision in LaraKube. |
| **2200** / **2022** | High SSH Ports | **RECOMMENDED ALTERNATE** | Common non-standard SSH ports; avoids all companion service collisions. |
| **Custom** | Any open port (1024–65535) | **ALLOWED** | Configurable per school/corporate firewall policy. |

---

## Part 1 — Database & Data Model (Desktop)

### 1. Database Migration
- Add `2026_10_15_000001_add_port_to_servers_table.php`:
  ```php
  Schema::table('servers', function (Blueprint $table) {
      $table->unsignedSmallInteger('port')->default(22)->after('ip');
  });
  ```

### 2. Model Updates (`desktop/app/Models/Server.php`)
- Add `'port'` to `$fillable`.
- Cast `'port'` to `'integer'`.
- Update `Server::syncFromStack()`:
  ```php
  'port' => (int) ($stack['port'] ?? $stack['sshPort'] ?? 22),
  ```

### 3. Catalog & Discovery (`desktop/app/Services/LaraKube/StackCatalog.php`)
- Update `StackItem` type definition in docblock:
  ```php
  * @phpstan-type StackItem array{name: string, provider: string, kind: string, region: ?string, ip: ?string, port?: ?int, context: ?string, sshKey?: ?string, bindings?: list<string>, account: ?string, projectId: ?string, status: string, role?: string, isCurrent?: bool}
  ```

---

## Part 2 — Form Validation & Controllers (Desktop)

### 1. Form Request Validation (`desktop/app/Http/Requests/StoreServerRequest.php`)
- Add rule for `ssh_port`:
  ```php
  'ssh_port' => ['nullable', 'integer', 'between:1,65535'],
  ```
- In `withValidator()`, add safety guards:
  - If `ssh_port == 443`: reject with error `"Port 443 is reserved for Traefik HTTPS ingress on single-node VPS clusters."`
  - If `ssh_port == 80`: reject with error `"Port 80 is reserved for Traefik HTTP web ingress."`
  - If `ssh_port == 2222`: reject with error `"Port 2222 is reserved for Git (Forgejo) SSH service."`

### 2. Server Controller (`desktop/app/Http/Controllers/ServerController.php`)
- In `store()`:
  - Read `$sshPort = $request->integer('ssh_port', 22) ?: 22;`
  - When `$targetKind === 'vps'` and `$sshPort !== 22`:
    - Append `"--ssh-port={$sshPort}"` to CLI arguments for `cloud:create`.

### 3. Dev Box Controller (`desktop/app/Http/Controllers/DevBoxController.php`)
- In `store()`:
  - Read `$sshPort = $request->integer('ssh_port', 22) ?: 22;`
  - When `$sshPort !== 22`:
    - Append `"--ssh-port={$sshPort}"` to CLI arguments for `devbox:create`.

### 4. Dev Box Remote Shell (`desktop/app/Services/LaraKube/DevBoxShell.php`)
- In `command(array $box, array $arguments, ?string $project = null, ?string $readSecret = null)`:
  - Extract `$port = (int) ($box['port'] ?? $box['sshPort'] ?? 22);`
  - Pass port argument to SSH:
    ```php
    return [
        $this->locator->find('ssh') ?? '/usr/bin/ssh',
        '-p', (string) $port,
        '-i', $key,
        '-o', 'BatchMode=yes',
        '-o', 'StrictHostKeyChecking=accept-new',
        '-o', 'ConnectTimeout=15',
        "larakube@{$ip}",
        'bash -lc '.escapeshellarg($remote),
    ];
    ```

---

## Part 3 — Frontend UI & User Experience

### 1. TypeScript Types (`desktop/resources/js/types/larakube.ts`)
- Update `Server` type:
  ```ts
  export type Server = {
      // ... existing fields
      port?: number | null;
  };
  ```

### 2. Server Creation Wizard (`desktop/resources/js/pages/servers/create.tsx`)
- In `useForm`:
  - Add `ssh_port: 22`.
- In Step 2 ("Customize Server" / "Customize Dev Box"):
  - Render an "SSH Port" field directly in the server configuration card.
  - Default value: `22`.
  - Include helper tip with quick-select recommendation pills (`8443 (Recommended for firewalls)`, `2200`).
  - Real-time client-side warning badges if `443` or `2222` are entered.

### 3. Server Details Page (`desktop/resources/js/pages/servers/show.tsx`)
- In the **Connection** card:
  - If `server.port && server.port !== 22`:
    - Display a dedicated row: `SSH Port: {server.port}`.
    - Provide a secondary copy button for direct IP connection: `ssh -p {server.port} larakube@{server.ip}` alongside the alias `ssh {server.name}`.
  - In `handleOpenTerminal('ssh')`:
    - `ssh {server.name}` continues to work because `~/.ssh/config` has `Port {server.port}` recorded.

### 4. Dev Box Details Page (`desktop/resources/js/pages/devboxes/show.tsx`)
- In the **Connection** card:
  - Display `SSH Port: {box.port}` when non-default (`box.port !== 22`).
  - Step 1 code snippet automatically reflects `-p {box.port}` if using direct IP or notes the configured port.

---

## Part 4 — CLI Contract Synchronization

Desktop relies on the CLI implementation being developed concurrently by Claude:
1. `cli/app/Commands/Cloud/CloudCreateCommand.php` & `DevBoxCreateCommand.php`:
   - Must accept `--ssh-port=<port>`.
   - Must pass `$sshPort` to `cloud:provision` / OpenTofu templates.
   - Must write `$sshPort` into `~/.ssh/config` via `$this->upsertSshConfigHost($stackName, $ip, 'larakube', (string)$sshPort, $keyPath)`.
2. `cli/app/Data/StackData.php`:
   - Must include `public int $port = 22` (or `public ?int $sshPort = null`).
3. `cli/app/Commands/Cloud/CloudStacksCommand.php`:
   - In `describeStacks()`, output `'port' => $stack->port ?? 22`.

---

## Verification & Testing Plan

### 1. Automated Tests
- **PHPUnit / Pest**:
  - `StoreServerRequestTest`: asserts validation passes with valid ports (e.g. 8443, 2200), rejects forbidden ports (443, 2222, 80), and bounds (0, 70000).
  - `ServerControllerTest`: asserts `--ssh-port` is appended to `cloud:create` arguments when specified.
  - `DevBoxShellTest`: asserts `-p <port>` is correctly injected into remote SSH process invocation.
  - `ServerModelTest`: asserts `port` column migration and `syncFromStack()` mapping.
- **Frontend Quality Assurance**:
  - `vp check` / `npx tsc --noEmit` clean without type errors.
  - Visual verification of Step 2 SSH Port field, pills, and error handling.

### 2. Manual Verification
- Test creating a VPS server with `ssh_port: 8443` on Desktop.
- Verify `~/.ssh/config` gets written with `Port 8443`.
- Verify Dev Box shell commands route over port 8443.
- Verify Connection card displays port 8443.
