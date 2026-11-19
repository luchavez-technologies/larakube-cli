# Plan: Cohesive 4-Tab Server Detail & Visual Backup Prune Drawer

**Status:** Proposed (Awaiting Approval)

## Summary
1. Reorganize `desktop/resources/js/pages/servers/show.tsx` into 4 tabs:
   - **Overview**: Diagnose, Hardware & Capacity, Domain & SSL (left) + Connection (right)
   - **Projects & Apps**: Hosted Projects, Apps
   - **Storage & Backups**: Plex Commons, Backups
   - **Settings**: Team Access & RBAC, OpenTofu Remote State (left) + Danger Zone (right)
2. Add `--json` support to `cli/app/Commands/Backup/BackupPruneCommand.php` and `POST /backups/prune-preview` in Desktop.
3. Replace the terminal Run preview in `desktop/resources/js/pages/backups/index.tsx` with a right-side slide-over drawer showing Keep/Delete pills, sizes, and direct prune confirmation.
