# Desktop Mail & Stalwart Management Implementation Plan

## Goal Description
Implement a first-class **Mail** management suite in LaraKube Desktop powered by Stalwart Mail Server:
- **Navigation & Server Switcher**: A top-level "Mail" entry in the desktop sidebar that defaults to the active server, with a server switcher dropdown allowing operators to manage mail across any server in their fleet.
- **Guided Setup State**: When Stalwart is not deployed on the selected server, present a clear, 1-click deployment wizard with domain input and quick-switch links to servers that already have Stalwart active.
- **Comprehensive Mail Dashboard**: When Stalwart is active, provide a clean horizontal tabbed interface:
  1. **Mailboxes**: List email accounts, create new mailboxes, reset passwords, set storage quotas, and delete accounts.
  2. **Domains & DNS Checklist**: Add email domains, display exact DNS records (MX, SPF, DKIM, DMARC) with one-click copy buttons, and run live DNS propagation checks.
  3. **Outbound Relay**: Configure external SMTP delivery providers (Amazon SES, Resend, Brevo, SendGrid, Mailgun) or direct port 25 delivery, plus test email dispatch.
  4. **Webmail & App Wiring**: One-click launcher for SnappyMail webmail and visibility into which Laravel projects are wired to Stalwart.
- **Strictly CLI-Driven Engine**: All data reads utilize `mail:* --json` flags, and all mutations execute via `CliRunner` as native `Run` processes with streaming terminal output.

---

## User Review Required
> [!IMPORTANT]
> - **Strictly CLI-Driven Architectural Standard**: In alignment with LaraKube's Dual-MCP / CLI engine design, Desktop does not maintain a duplicate private JMAP client. Every read utilizes `mail:* --json`, and every action executes via `CliRunner`. This ensures fixes to DKIM generation, port forwarding, or JMAP schemas in the CLI immediately benefit the Desktop UI.
> - **DNS Automation Guidance**: Because DNS records must be configured in external DNS providers (Cloudflare, DigitalOcean, Route53), the "Domains & DNS" tab provides clear copy-paste snippets (Host, Type, Value) alongside a live `mail:check` validator.

---

## Architecture & Data Flow

```mermaid
flowchart TD
    subgraph Desktop UI ["Desktop UI (React / Inertia)"]
        Nav["Sidebar: Mail Nav Item"]
        Switcher["Server Switcher Dropdown"]
        EmptyState["Guided Stalwart Deployment Card"]
        
        subgraph Tabs ["Mail Management Tabs"]
            T_Boxes["Mailboxes Tab (Create / Reset / Quota)"]
            T_Domains["Domains & DNS Tab (Records / Check)"]
            T_Relay["Outbound Relay Tab (SES / Resend / Direct)"]
            T_Webmail["Webmail & Connected Apps Tab"]
        end
    end

    subgraph Desktop Backend ["Desktop Backend (Laravel / NativePHP)"]
        MC["MailController"]
        MS["MailStatus Service (Invokes CLI --json)"]
        CR["CliRunner (Executes Runs)"]
    end

    subgraph CLI Engine ["LaraKube CLI"]
        Read_Show["mail:show --json"]
        Read_Domains["mail:domains --json"]
        Read_Accounts["mail:accounts --json"]
        Read_Dns["mail:dns <domain> --json"]
        Read_Check["mail:check <domain> --json"]

        Mut_Init["mail:init / tool:init --tool=stalwart"]
        Mut_Create["mail:create <email> --password= --quota="]
        Mut_Pass["mail:password <email> --password="]
        Mut_Del["mail:delete <email> --force"]
        Mut_Dom["mail:domain <domain>"]
        Mut_Relay["mail:relay --provider= ..."]
        Mut_Test["mail:test <to>"]
    end

    subgraph Cluster ["Target Kubernetes Cluster"]
        Stalwart["Stalwart Mail Server (Pod / JMAP API)"]
        Snappy["SnappyMail Webmail (Pod)"]
    end

    Nav --> MC
    Switcher --> MC
    MC --> MS
    MS -->|Background reads| Read_Show
    MS -->|Background reads| Read_Domains
    MS -->|Background reads| Read_Accounts
    MS -->|Background reads| Read_Dns
    MS -->|Background reads| Read_Check

    T_Boxes -->|POST /servers/{server}/mail/accounts/*| MC
    T_Domains -->|POST /servers/{server}/mail/domains/*| MC
    T_Relay -->|POST /servers/{server}/mail/relay| MC
    EmptyState -->|POST /servers/{server}/mail/deploy| MC

    MC --> CR
    CR --> Mut_Init
    CR --> Mut_Create
    CR --> Mut_Pass
    CR --> Mut_Del
    CR --> Mut_Dom
    CR --> Mut_Relay
    CR --> Mut_Test

    Read_Show --> Stalwart
    Read_Domains --> Stalwart
    Read_Accounts --> Stalwart
    Mut_Create --> Stalwart
    T_Webmail --> Snappy
```

---

## Proposed Changes

### 1. CLI Commands & JSON Mode (`cli/app/Commands/Mail/`)

#### [MODIFY] `MailDomainsCommand.php`
- Add `{--json : Emit machine-readable JSON list of domains}`.
- Emits:
  ```json
  [
    { "domain": "acme.com", "createdAt": "2026-10-01T12:00:00Z" }
  ]
  ```

#### [MODIFY] `MailAccountsCommand.php`
- Add `{--json : Emit machine-readable JSON list of mailboxes}`.
- Emits:
  ```json
  [
    { "email": "support@acme.com", "name": "Support", "quota": "10GiB", "used": "1.2GiB" }
  ]
  ```

#### [MODIFY] `MailDnsCommand.php`
- Add `{--json : Emit machine-readable DNS records table}`.
- Emits:
  ```json
  {
    "domain": "acme.com",
    "records": [
      { "type": "MX", "host": "@", "value": "mail.acme.com", "priority": 10 },
      { "type": "TXT", "host": "@", "value": "v=spf1 mx ~all" },
      { "type": "TXT", "host": "stalwart._domainkey", "value": "v=DKIM1; k=ed25519; p=..." },
      { "type": "TXT", "host": "_dmarc", "value": "v=DMARC1; p=reject;" }
    ]
  }
  ```

#### [MODIFY] `MailCheckCommand.php`
- Add `{--json : Emit live DNS resolution verification results}`.
- Emits:
  ```json
  {
    "domain": "acme.com",
    "status": "partial",
    "checks": {
      "mx": { "passed": true, "expected": "mail.acme.com", "actual": "mail.acme.com" },
      "spf": { "passed": true },
      "dkim": { "passed": false, "error": "Record not found on DNS nameservers" },
      "dmarc": { "passed": true }
    }
  }
  ```

#### [MODIFY] `MailShowCommand.php`
- Add `{--json : Emit mail server connection, admin URL, and webmail status}`.

#### [MODIFY] Non-Interactive Flags for Mutations:
- `MailCreateCommand`: ensure `{--password=}`, `{--quota=}`, `{--name=}`, `{--force}` allow fully headless execution.
- `MailPasswordCommand`: ensure `{--password=}`, `{--force}` allow headless password resets.
- `MailDeleteCommand`: ensure `{--force}` bypasses deletion confirmation.
- `MailDomainCommand`: ensure headless domain creation and DKIM minting.
- `MailRelayCommand`: ensure flags for `{--provider=}`, `{--host=}`, `{--port=}`, `{--username=}`, `{--password=}`.

---

### 2. Desktop Backend (`desktop/app/`)

#### [NEW] `desktop/app/Http/Controllers/MailController.php`
Handles all Mail web routes and server dispatching:
- `entry()`: Redirects `/mail` to `/servers/{current}/mail` using the last browsed server or first ready server.
- `index(string $server, MailStatus $status)`:
  - Validates server context.
  - Queries Stalwart installation status via `mail:show --json`.
  - If installed: defers `domains`, `accounts`, and `serverInfo`.
  - If not installed: returns empty state with list of other servers with Stalwart active.
- `deploy(Request $request, string $server)`: Launches `mail:init` via `CliRunner`.
- `createAccount(Request $request, string $server)`: Launches `mail:create`.
- `deleteAccount(Request $request, string $server)`: Launches `mail:delete --force`.
- `resetPassword(Request $request, string $server)`: Launches `mail:password --force`.
- `addDomain(Request $request, string $server)`: Launches `mail:domain`.
- `checkDns(Request $request, string $server, string $domain)`: Returns live DNS check results via JSON.
- `configureRelay(Request $request, string $server)`: Launches `mail:relay`.
- `sendTest(Request $request, string $server)`: Launches `mail:test`.

#### [NEW] `desktop/app/Services/LaraKube/MailStatus.php`
Executes CLI commands synchronously for JSON reads with caching:
- `checkInstalled(string $context): bool`
- `getServerInfo(string $context): ?array`
- `getDomains(string $context): array`
- `getAccounts(string $context): array`
- `getDnsRecords(string $context, string $domain): array`
- `checkDns(string $context, string $domain): array`

#### [MODIFY] `desktop/app/Enums/RunKind.php`
Register dedicated `RunKind` cases:
```php
case MailDeploy = 'mail-deploy';
case MailCreateAccount = 'mail-create-account';
case MailDeleteAccount = 'mail-delete-account';
case MailResetPassword = 'mail-reset-password';
case MailAddDomain = 'mail-add-domain';
case MailConfigureRelay = 'mail-configure-relay';
case MailSendTest = 'mail-send-test';
```

#### [MODIFY] `desktop/routes/web.php`
Register top-level Mail routes:
```php
Route::get('/mail', [MailController::class, 'entry'])->name('mail');
Route::get('/servers/{server}/mail', [MailController::class, 'index'])->name('servers.mail.index');
Route::post('/servers/{server}/mail/deploy', [MailController::class, 'deploy'])->name('servers.mail.deploy');
Route::post('/servers/{server}/mail/accounts', [MailController::class, 'createAccount'])->name('servers.mail.accounts.store');
Route::post('/servers/{server}/mail/accounts/password', [MailController::class, 'resetPassword'])->name('servers.mail.accounts.password');
Route::delete('/servers/{server}/mail/accounts', [MailController::class, 'deleteAccount'])->name('servers.mail.accounts.destroy');
Route::post('/servers/{server}/mail/domains', [MailController::class, 'addDomain'])->name('servers.mail.domains.store');
Route::get('/servers/{server}/mail/domains/{domain}/dns', [MailController::class, 'dnsRecords'])->name('servers.mail.domains.dns');
Route::get('/servers/{server}/mail/domains/{domain}/check', [MailController::class, 'checkDns'])->name('servers.mail.domains.check');
Route::post('/servers/{server}/mail/relay', [MailController::class, 'configureRelay'])->name('servers.mail.relay.store');
Route::post('/servers/{server}/mail/test', [MailController::class, 'sendTest'])->name('servers.mail.test');
```

---

### 3. Desktop Frontend UI (`desktop/resources/js/`)

#### [MODIFY] `desktop/resources/js/layouts/app-layout.tsx`
Add `Mail` to sidebar navigation (under `Tools`, with a clean `Mail` icon from `lucide-react`):
```tsx
{
    label: 'Mail',
    description: 'Email & mailboxes',
    href: '/mail',
    active: (url) => url.startsWith('/mail') || /^\/servers\/[^/]+\/mail/.test(url),
    accent: 'bg-emerald-600',
    icon: Mail,
}
```

#### [NEW] `desktop/resources/js/pages/mail/index.tsx`
The main Mail page featuring:
1. **Server Header & Switcher**:
   - Title: "Mail Management".
   - Dropdown switcher: Select active server (badges indicate if Stalwart is active).
   - Quick-action buttons: "Open Webmail" (external link with icon), "Stalwart Admin".
2. **Empty State Component (`mail-empty-state.tsx`)**:
   - Visual onboarding graphic.
   - Explanatory bullets: SMTP/IMAP/JMAP, automatic DKIM, Webmail, spam filtering.
   - Primary domain input field (`mail.example.com`).
   - "Deploy Stalwart Mail Server" primary button.
   - Quick-switch links if Stalwart is detected on another server.
3. **Tabbed Mail Management Dashboard**:
   - **Tab 1: Mailboxes (`mailboxes-tab.tsx`)**:
     - Action bar: Search mailboxes, "New Mailbox" button.
     - Table: Email address, Display Name, Quota Progress Bar, Reset Password button, Delete button.
     - Modal: "Create Mailbox" with generated password toggle.
     - Modal: "Reset Password" with clipboard copy.
   - **Tab 2: Domains & DNS (`domains-tab.tsx`)**:
     - "Add Domain" button (`mail:domain`).
     - Domain cards with expandable DNS Checklist:
       - MX, SPF, DKIM, and DMARC table.
       - One-click copy buttons for Host, Type, and Value.
       - "Verify DNS" button with live status indicator (Passed / Pending / Error).
   - **Tab 3: Outbound Relay (`relay-tab.tsx`)**:
     - Select provider: Direct Delivery, Amazon SES, Resend, Brevo, SendGrid, Mailgun, Custom SMTP.
     - Credentials inputs (API key, user, pass, region).
     - Test email drawer: send test email to confirm delivery.
   - **Tab 4: Settings & Webmail (`settings-tab.tsx`)**:
     - Webmail URL & status.
     - Connected Laravel apps table (showing which apps have `mail:wire` configured).
     - Stalwart service restart & logs links.

---

## Verification Plan

### Automated Tests
1. **CLI Tests (`cli/tests/Feature/`)**:
   - `MailDomainsJsonTest`: Test `mail:domains --json` output structure.
   - `MailAccountsJsonTest`: Test `mail:accounts --json` output structure.
   - `MailDnsJsonTest`: Test `mail:dns <domain> --json` record generation.
   - `MailCheckJsonTest`: Test `mail:check <domain> --json` resolver assertions.
2. **Desktop Tests (`desktop/tests/Feature/`)**:
   - `MailControllerTest`:
     - Test `/mail` redirection to last/ready server.
     - Test index rendering with Stalwart installed vs uninstalled.
     - Test account creation, password reset, and deletion runs.
     - Test domain creation and relay configuration runs.
3. **Quality & Linters**:
   - `composer analyse` (PHPStan) in both `cli/` and `desktop/`.
   - `composer test` in both `cli/` and `desktop/`.
   - `npm run check` and `npm run types:check` in `desktop/`.
   - `npm run build` in `desktop/`.

### Manual Verification
1. Click **Mail** in the sidebar: verify it navigates to `/servers/{current}/mail` with the server switcher preselected.
2. Toggle server switcher: verify smooth transition between servers.
3. On a server without Stalwart: verify the guided deployment card renders with domain input and quick-switch suggestions.
4. On a server with Stalwart:
   - Check Mailboxes tab: create a test mailbox, reset password, verify quota.
   - Check Domains tab: copy DKIM and SPF records, run DNS verification check.
   - Check Relay tab: review provider options and test email dispatch.
   - Check Webmail launcher: click "Open Webmail" and ensure it opens the correct URL.
