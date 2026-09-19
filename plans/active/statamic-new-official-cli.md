# Plan: `statamic:new` on the official Statamic CLI, with starter kits, storage choice and a super user

**Status:** 📝 PLANNED, not started.

## Why
- `statamic:new` runs `composer create-project statamic/statamic`, which can't
  install a starter kit. `larakube new` runs the official `laravel new` in a
  container and forwards its flags; Statamic should work the same way.
- A new site has no way to log in: the user has to know to run
  `larakube art make:statamic-user` afterwards.
- The wizard provisions a Commons database, but Statamic keeps content and
  users in flat files unless the Eloquent driver is installed. File users
  (`users/<email>.yaml`, bcrypt hash) are committed to Git by default, so a
  local super user and its password ship to production; Control Panel edits
  made in production live inside the container and are lost on redeploy.
- The installer runs through `passthru()`, which tests can't fake, so the
  wizard has no test.

## Design
1. **Official installer.** In the build container:
   `composer global require statamic/cli` (pin checked at build time; 3.6.4
   at planning) then `statamic new <name> [<kit>] --no-interaction
   --no-ascii-art`, run through `Process` (tty) so tests can fake it.
2. **Flags forwarded**, as `larakube new` forwards Laravel's:
   `--starter-kit=vendor/kit` (a flag, not a positional), `--pro`,
   `--license=`, `--with-config`, `--without-dependencies`, `--ssg`, `--git`.
   Interactive runs ask for the kit (blank = none); paid kits need
   `--license=` when unattended.
3. **Where content and users live:** `--content=database|files`, asked in the
   wizard, default **database**.
   - *database*: Statamic's official Eloquent driver for content, and the
     Eloquent user repository, on the Commons database the wizard already
     provisions. Per-environment users; production edits persist.
   - *files*: Statamic's default. The wizard says plainly that content and
     users travel through Git and that production Control Panel edits don't
     survive a redeploy.
   Verify the exact setup commands against the current Statamic 6 docs at
   build time (the latest was v6.33.0 at planning).
4. **Super user in the wizard:** email + password (`password()` prompt), never
   in argv. A small PHP script in the build container bootstraps the app and
   calls `Statamic\Facades\User::make()->email()->password()->makeSuper()
   ->save()`, reading both from environment variables.
   - *files*: created at scaffold time. The password must be strong (length +
     common-password check), because it becomes a production login.
   - *database*: created after the first `larakube up` (migrations have run),
     local only. Production's first super user comes from a command run
     against the cloud environment (design it with this change; no hidden
     flag on another command).
   - Unattended runs: `--email=` plus the password from an environment
     variable; without them, skip and print how to create one.
5. **Clean-ups:** delete the unused `resources/views/k8s/statamic/*.blade.php`
   (Statamic renders through the Laravel templates).

## Tests
- The generated `statamic new` command line for each flag combination; no
  password anywhere in any recorded command.
- `--content` wiring for both modes (config the scaffold leaves behind).
- Super-user script gets its values from the environment only; files mode
  rejects a weak password; unattended without a password skips cleanly.

## Verification (standalone walkthrough, written with the change)
Real runs before handing it over, each followed by `larakube up` and a Control
Panel login:
1. `statamic:new demo` (no kit), database mode.
2. `statamic:new demo --starter-kit=jasonbaciulis/bedrock`, both modes. Bedrock
   lists Statamic 5 while the latest is 6, and it overwrites
   `config/filesystems.php` and `.env.example`; confirm our storage config
   still wins and whether it needs files mode.
3. `cloud:configure` + deploy for one of them; log in on production with the
   production super user, not the local one.
