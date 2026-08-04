# Plan: Cluster Tools Registry & `.larakube.json` Cleanup (`*InitCommand` Refactor)

> **Status:** Draft  
> **Created:** 2026-08-05  
> **Target Version:** LaraKube CLI v1.2.0

---

## 1. Goal Description

Cluster tools (`ClusterTool::cases()`) are **cluster-wide infrastructure** in `larakube-shared`. Their state, hostnames, and metadata belong strictly inside the **Cluster Tool Registry** (`larakube-tools-registry` K8s Secret). They have no relationship to any individual Laravel project and must **never touch or mutate `.larakube.json`**.

Currently, several `*InitCommand` classes carry legacy `promptForCloud...Host()` helper functions that call `$config->setHost(...)` and `$config->saveToFile(...)`.

This plan refactors all **24 `*InitCommand` classes** to use the `ResolvesToolHost` trait, ensuring every tool's hostname is resolved from and saved to the **Cluster Tool Registry**, completely removing all `.larakube.json` file mutations from cluster tool commands.

---

## 2. User Review Required

> [!IMPORTANT]
> **Separation of Powers**: `.larakube.json` is strictly the project blueprint for a user's web/app repository (Laravel/Statamic/WordPress/Next.js). Cluster tools will store 100% of their hostname and metadata inside the cluster registry (`larakube-tools-registry`), keeping `.larakube.json` clean and unpolluted.

---

## 3. Proposed Changes

### Target Commands to Refactor (All 24 Cluster Tool Init Commands)

1. **`app/Commands/Analytics/AnalyticsInitCommand.php`**
2. **`app/Commands/Chat/ChatInitCommand.php`**
3. **`app/Commands/Crm/CrmInitCommand.php`**
4. **`app/Commands/Data/DataInitCommand.php`**
5. **`app/Commands/Desk/DeskInitCommand.php`**
6. **`app/Commands/Drive/DriveInitCommand.php`**
7. **`app/Commands/Errors/ErrorsInitCommand.php`**
8. **`app/Commands/Flow/FlowInitCommand.php`**
9. **`app/Commands/Git/GitInitCommand.php`**
10. **`app/Commands/Insights/InsightsInitCommand.php`**
11. **`app/Commands/Link/LinkInitCommand.php`**
12. **`app/Commands/Mail/MailInitCommand.php`**
13. **`app/Commands/Monitor/MonitorInitCommand.php`**
14. **`app/Commands/Notes/NotesInitCommand.php`**
15. **`app/Commands/Password/PasswordsInitCommand.php`**
16. **`app/Commands/Record/RecordInitCommand.php`**
17. **`app/Commands/Secrets/SecretsInitCommand.php`**
18. **`app/Commands/Sheet/SheetsInitCommand.php`**
19. **`app/Commands/Sign/SignInitCommand.php`**
20. **`app/Commands/Sso/SsoInitCommand.php`**
21. **`app/Commands/Support/SupportInitCommand.php`**
22. **`app/Commands/Tasks/TasksInitCommand.php`**
23. **`app/Commands/Uptime/UptimeInitCommand.php`**
24. **`app/Commands/Vpn/VpnInitCommand.php`**
25. **`app/Commands/Webmail/WebmailInitCommand.php`**

### Refactoring Pattern

In each `*InitCommand`:
- Add `use ResolvesToolHost;` to the command class.
- Replace custom `resolve...Host()` and `promptForCloud...Host()` methods with:
  ```php
  $host = $this->resolveToolHost(
      SharedClusterService::<SERVICE>,
      ClusterTool::<TOOL>,
      $env,
      $kubectl,
  );
  ```
- Remove all `$config->setHost()` and `$config->saveToFile()` code blocks.
- Ensure `$this->registerDeployedTool(ClusterTool::<TOOL>, $kubectl, $host);` is invoked upon successful deployment.

---

## 4. Verification Plan

### Automated Tests
1. Run Pint code formatter:
   ```bash
   php vendor/bin/pint
   ```
2. Run PHPStan static analysis:
   ```bash
   php -d memory_limit=1G vendor/bin/phpstan analyse
   ```
3. Run Pest test suite across all command and trait tests:
   ```bash
   php -d memory_limit=1G vendor/bin/pest
   ```
