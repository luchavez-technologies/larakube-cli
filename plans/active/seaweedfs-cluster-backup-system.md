# Plan: SeaweedFS Automated & Per-Tool Backup Engine

**Status:** 🗄️ SUPERSEDED — verified 2026-08-08: replaced by the shipped `backup:*` suite (ADR 0010). Backing SeaweedFS up onto SeaweedFS was the specific thing that design got wrong — it is the same disk.

---

## 🎯 Objective

Provide a zero-data-loss backup, restore, and pruning architecture powered by **SeaweedFS S3 Storage** on `larakube-shared`.

---

## 📋 Implementation Checklist

- [ ] Create `InteractsWithSeaweedFs.php` trait (SeaweedFS S3 upload/download/list helper)
- [ ] Create `BackupRunCommand.php` (`larakube backup:run {tool?}`)
- [ ] Create `BackupListCommand.php` (`larakube backup:list {tool?}`)
- [ ] Create `BackupRestoreCommand.php` (`larakube backup:restore {tool?}`)
- [ ] Create `BackupPruneCommand.php` (`larakube backup:prune` - 7 Daily / 4 Weekly / 3 Monthly GFS retention)
- [ ] Create `BackupInitCommand.php` (`larakube backup:init` - Nightly 02:00 AM CronJob)
- [ ] Write Pest feature tests (`tests/Feature/BackupCommandTest.php`)
- [ ] Run PHPStan static analysis (`./php vendor/bin/phpstan`)

---

## 💡 Key Workflows

### 1. Targeted Per-Tool Backup (Before App Upgrades)
```bash
larakube backup:run data
```
Takes a targeted snapshot of Directus DB (`directus`) & Directus uploads PVC (`data-directus-pvc`) to SeaweedFS S3 in under 5 seconds.

### 2. Full Nightly Automation
```bash
larakube backup:init
```
Deploys `larakube-backup-agent` CronJob running nightly at 02:00 AM UTC.

### 3. GFS Pruning
```bash
larakube backup:prune
```
Automatically deletes backups older than 7 days (daily), 4 weeks (weekly), or 3 months (monthly).
