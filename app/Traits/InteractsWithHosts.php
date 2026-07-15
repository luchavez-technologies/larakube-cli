<?php

namespace App\Traits;

use App\Data\GlobalConfigData;
use App\Enums\SharedClusterService;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;

trait InteractsWithHosts
{
    use DetectsWsl, InteractsWithOs, InteractsWithProjectConfig, InteractsWithTrust, LaraKubeOutput;

    /**
     * Resolve the externally-reachable IP for cluster ingress.
     *
     * Tries LoadBalancer IP first (cloud / Docker Desktop), then falls back to
     * the node's InternalIP. On WSL2 + native k3s the LoadBalancer has no
     * external IP assigned, so the node IP is the only reliable address that
     * works from both WSL and the Windows browser.
     */
    protected function resolveIngressIp(): string
    {
        // Prefer the LoadBalancer IP when cloud assigns one.
        $lbIp = trim(Process::run(
            "kubectl get svc traefik -n traefik -o jsonpath='{.status.loadBalancer.ingress[0].ip}'",
        )->output());
        if ($lbIp !== '') {
            return $lbIp;
        }

        // Fall back to the node's InternalIP — the canonical routable address
        // for WSL2 / bare-metal k3s where no cloud LoadBalancer IP exists.
        return trim(Process::run(
            "kubectl get nodes -o jsonpath='{.items[0].status.addresses[?(@.type==\"InternalIP\")].address}'",
        )->output()) ?: '127.0.0.1';
    }

    /**
     * Check and optionally update the hosts file(s) based on project context.
     * On WSL this also syncs the Windows hosts file, since the Windows browser
     * doesn't read WSL's /etc/hosts.
     *
     * @param  array  $customHosts  Optional specific hosts to map. If empty, uses the current project's hosts.
     * @param  string|null  $customAppName  Optional app name to group the block. Defaults to the current directory name.
     * @return string|null A one-line summary if the Windows hosts file (WSL2 only) needed syncing but
     *                     wasn't fully synced — null otherwise. Every other outcome here already prints
     *                     inline; this return value exists solely so a long-running caller (UpCommand)
     *                     can re-surface it as an end-of-run reminder instead of it scrolling away.
     */
    protected function ensureHostsAreSet(array $customHosts = [], ?string $customAppName = null): ?string
    {
        $projectPath = getcwd();
        $appName = $customAppName ?? basename($projectPath);
        $config = null;

        if (empty($customHosts)) {
            $config = $this->getProjectConfig($projectPath);

            // No readable config → nothing to map. Host syncing is a convenience
            // pre-step; skip it rather than crash (getProjectConfig already
            // surfaced the reason if the file exists but is invalid).
            if (! $config) {
                return null;
            }

            $requiredHosts = array_keys($config->getAllHosts('local'));
        } else {
            $requiredHosts = $customHosts;
        }

        if (empty($requiredHosts)) {
            return null;
        }

        // If dnsmasq is already wildcarding this project's TLD (its own pinned
        // override, or the developer's global default), individual /etc/hosts
        // entries are redundant — skip the prompt entirely.
        // On WSL2 dnsmasq runs inside the VM and is invisible to Windows browsers,
        // so we always fall through to the Windows hosts file sync instead.
        $tld = $config?->getLocalTld() ?? GlobalConfigData::load()->getLocalTld();

        if (! $this->isWsl() && $this->dnsmasqCoversKube($tld)) {
            return null;
        }

        // dnsmasq is set up but doesn't know about this TLD yet — offer to extend it.
        // Skip on WSL2: dnsmasq inside the VM doesn't help Windows browsers.
        if (! $this->isWsl()
            && $this->isDnsmasqInstalled()
            && confirm("dnsmasq is set up but doesn't cover .{$tld} yet — extend it to cover this project too? (requires sudo)")
        ) {
            $this->configureDnsmasq($tld);

            return null;
        }

        // 🪟 WSL: the Windows browser can't see WSL's /etc/hosts, so also sync
        // the Windows hosts file. Done first/independently of the Linux sync.
        $windowsWarning = null;
        if ($this->isWsl()) {
            $windowsWarning = $this->ensureWindowsHostsAreSet($requiredHosts, $appName);
        }

        $externalIp = $this->resolveIngressIp();
        $hostList = implode(' ', $requiredHosts);
        $newEntry = "$externalIp $hostList";
        $blockIdentifier = "# LaraKube: $appName";
        $fullBlock = "\n{$blockIdentifier}\n{$newEntry}\n";

        // 1. Check if update is actually needed
        if (! file_exists('/etc/hosts')) {
            return $windowsWarning;
        }

        // If running inside a container, we usually can't update the host's /etc/hosts
        // without mapping it, which is risky. We'll skip it and warn the user.
        if (getenv('LARAKUBE_HOST_PROJECT_PATH') && ! is_writable('/etc/hosts')) {
            $this->warning('Running inside LaraKube daemon: skipping /etc/hosts sync.');
            $this->line('  👉 Please ensure your host machine has these mappings:');
            $this->line("     $newEntry");
            $this->line('');

            return $windowsWarning;
        }

        $currentHosts = file_get_contents('/etc/hosts');

        if (str_contains($currentHosts, $fullBlock)) {
            return $windowsWarning;
        }

        $this->laraKubeInfo('Local domain mapping update required.');
        $this->line("  <fg=gray>Target IP:</> <fg=blue>$externalIp</>");
        foreach ($requiredHosts as $host) {
            $this->line("  <fg=gray>●</> <fg=blue>{$host}</>");
        }
        $this->line('');

        if (confirm('Would you like LaraKube to sync your /etc/hosts?')) {
            $this->line('  <fg=gray>LaraKube requires sudo privileges to update /etc/hosts</>');
            passthru('sudo -v');

            $success = $this->withSpin('Syncing /etc/hosts...', function () use ($currentHosts, $blockIdentifier, $newEntry) {
                $newHosts = $this->applyHostsBlock($currentHosts, $blockIdentifier, $newEntry);

                return $this->writeToEtcHosts($newHosts);
            });

            if ($success) {
                $this->laraKubeInfo('Hosts synchronized successfully!');
            } else {
                $this->laraKubeError('Failed to update /etc/hosts. Check your sudo permissions and try again.');
            }
        }

        return $windowsWarning;
    }

    /**
     * Sync cluster-wide shared service hosts (Mailpit, Traefik dashboard, Console,
     * Grafana) into /etc/hosts and the Windows hosts file on WSL.
     *
     * Called after reconcileSharedCluster() in UpCommand. Always-on services
     * (Mailpit) are deployed unconditionally, so their host is always synced.
     * Install-gated services (Traefik dashboard, Console, Grafana) are only
     * RE-POINTED by reconcileSharedCluster() when already present — `up` never
     * auto-installs them — so their host is only synced once the same presence
     * probe confirms they're actually there. Otherwise a project that never ran
     * `monitor:init` would get a "grafana.<tld>" entry pointing at an Ingress
     * that was never created.
     *
     * Unlike ensureHostsAreSet() this is fully automated (no confirm prompt) because
     * the hosts are managed by LaraKube itself, not user project config, and they
     * only contain the cluster ingress IP (not a wildcard catch-all).
     */
    protected function syncClusterServiceHosts(): void
    {
        $domain = GlobalConfigData::load()->getLocalTld();

        $hosts = [];
        foreach (SharedClusterService::cases() as $service) {
            if (! $service->targetsEnvironment('local')) {
                continue;
            }

            $probe = $service->presenceProbe();
            if ($probe !== null && trim(Process::run("kubectl get {$probe} --no-headers")->output()) === '') {
                continue;
            }

            $hosts[] = $service->host($domain);
        }

        if ($hosts === []) {
            return;
        }

        // If dnsmasq already wildcards this TLD to 127.0.0.1, a static
        // /etc/hosts entry is redundant AND actively harmful: it pins these
        // hosts to whatever the cluster's LoadBalancer IP was at write time,
        // which breaks everything once that IP goes stale (e.g. after an
        // OrbStack restart) — while dnsmasq-covered hosts keep resolving
        // correctly through 127.0.0.1 regardless. Same guard
        // ensureHostsAreSet() already uses for regular project hosts; also
        // clean up any stale entry a previous `up` run left behind before
        // dnsmasq covered this TLD.
        if (! $this->isWsl() && $this->dnsmasqCoversKube($domain)) {
            $this->removeHostsBlock('larakube-shared');

            return;
        }

        // On WSL also sync the Windows hosts file (browsers run on Windows, not WSL).
        if ($this->isWsl()) {
            $this->syncWindowsHosts($hosts, 'larakube-shared');
        }

        $this->syncHostsEntries($hosts, 'larakube-shared');
    }

    /**
     * Write a list of hosts to /etc/hosts for the given app name block.
     * Automated (no confirm prompt) — caller owns the UX decision.
     *
     * @param  array<int, string>  $hosts
     */
    protected function syncHostsEntries(array $hosts, string $appName): void
    {
        if ($hosts === [] || ! file_exists('/etc/hosts')) {
            return;
        }

        $ingressIp = $this->resolveIngressIp();
        $entry = "{$ingressIp} ".implode(' ', $hosts);
        $blockId = "# LaraKube: {$appName}";
        $current = (string) file_get_contents('/etc/hosts');
        $updated = $this->applyHostsBlock($current, $blockId, $entry);

        if (rtrim($updated) === rtrim($current)) {
            return;
        }

        // No confirm prompt (this is LaraKube-managed, not the user's project
        // hosts — see docblock), but the sudo password prompt that follows
        // needs SOME explanation, or it looks like it's coming from nowhere.
        $this->line("  <fg=gray>Updating /etc/hosts for {$appName} (requires sudo)...</>");
        passthru('sudo -v');

        if (! $this->writeToEtcHosts($updated)) {
            $this->laraKubeWarn("Failed to update /etc/hosts for {$appName}. Check your sudo permissions.");
        }
    }

    /**
     * Remove a previously-written LaraKube /etc/hosts block (identified the
     * same way syncHostsEntries() writes one), without replacing it with
     * anything. Used when dnsmasq now covers the TLD: a static entry pinned
     * to whatever the cluster's LoadBalancer IP was at write time is not just
     * redundant but actively harmful once that IP goes stale (e.g. after an
     * OrbStack restart) — it overrides the dnsmasq wildcard (→ 127.0.0.1)
     * that would otherwise keep resolving correctly. No-op if the block
     * isn't present.
     */
    protected function removeHostsBlock(string $appName): void
    {
        if (! file_exists('/etc/hosts')) {
            return;
        }

        $blockId = "# LaraKube: {$appName}";
        $current = (string) file_get_contents('/etc/hosts');
        $pattern = '/\n?'.preg_quote($blockId, '/')."\n[^\n]*\n?/";
        $stripped = preg_replace($pattern, "\n", $current) ?? $current;

        if (rtrim($stripped) === rtrim($current)) {
            return;
        }

        $this->line("  <fg=gray>Removing stale /etc/hosts entry for {$appName} (dnsmasq already covers this TLD)...</>");
        passthru('sudo -v');

        if (! $this->writeToEtcHosts($stripped)) {
            $this->laraKubeWarn("Failed to update /etc/hosts for {$appName}. Check your sudo permissions.");
        }
    }

    /**
     * Write $content to /etc/hosts via sudo, returning whether it succeeded.
     * Stages the content in a randomly-named temp file first — a hardcoded
     * /tmp path would let any local user race it with a symlink before the
     * `sudo cp` follows it into /etc/hosts.
     */
    protected function writeToEtcHosts(string $content): bool
    {
        $tmpPath = (string) tempnam(sys_get_temp_dir(), 'larakube_hosts');
        file_put_contents($tmpPath, $content);

        $ok = Process::run('sudo cp '.escapeshellarg($tmpPath).' /etc/hosts')->successful();
        @unlink($tmpPath);

        return $ok;
    }

    /**
     * Write cluster-wide shared service hosts to the Windows hosts file from WSL.
     * Idempotent: skips if already up to date.
     *
     * @param  array<int, string>  $hosts
     */
    protected function syncWindowsHosts(array $hosts, string $appName): void
    {
        $winHosts = $this->windowsHostsPath();
        if (! file_exists($winHosts)) {
            return;
        }

        $ingressIp = $this->resolveIngressIp();
        $entry = "{$ingressIp} ".implode(' ', $hosts);
        $blockId = "# LaraKube: {$appName}";
        $current = (string) file_get_contents($winHosts);
        $updated = $this->applyHostsBlock($current, $blockId, $entry);

        if (rtrim($updated) === rtrim($current)) {
            return;
        }

        // UAC auto-elevation across the WSL→Windows boundary is unreliable (it can
        // just flash a window and fail) — mirrors ensureWindowsHostsAreSet(). This
        // path stays automated (no confirm prompt, per the docblock above), but a
        // failure must still be visible instead of leaving the entry silently
        // missing from the Windows hosts file.
        if (! $this->hasWslInterop()) {
            $this->warnWslInteropDown();
            $this->printWindowsHostsManualHelp($entry);

            return;
        }

        if (! $this->syncWindowsHostsFile($updated, $entry)) {
            $this->laraKubeWarn("Could not sync the Windows hosts file automatically for {$appName}.");
        }
    }

    /**
     * Sync project domains into the Windows hosts file from WSL.
     *
     * The Windows hosts file requires Administrator rights, so we don't write to
     * /mnt/c/... directly (it would fail with permission denied). Instead we drop
     * a tiny .ps1 and run it elevated via PowerShell's Start-Process -Verb RunAs
     * (the standard UAC prompt).
     *
     * On WSL2 with native k3s, the ingress runs on the WSL node's InternalIP
     * (e.g. 172.31.x.x) — NOT 127.0.0.1 — because Windows and WSL have separate
     * loopback interfaces. Windows can reach the WSL node IP directly.
     *
     * @param  array<int, string>  $requiredHosts
     */
    protected function ensureWindowsHostsAreSet(array $requiredHosts, string $appName): ?string
    {
        $winHosts = $this->windowsHostsPath();

        if (! file_exists($winHosts)) {
            return null; // Non-standard WSL mount; nothing we can safely do.
        }

        $blockIdentifier = "# LaraKube: $appName";
        $ingressIp = $this->resolveIngressIp();
        $entry = "{$ingressIp} ".implode(' ', $requiredHosts);

        $current = (string) file_get_contents($winHosts);
        $updated = $this->applyHostsBlock($current, $blockIdentifier, $entry);

        // Already in sync (ignoring trailing-whitespace differences).
        if (rtrim($updated) === rtrim($current)) {
            return null;
        }

        $this->laraKubeInfo('Windows hosts file needs updating (so your Windows browser resolves these).');
        $this->printWindowsHostsManualHelp($entry);

        // Editing the Windows hosts file needs admin, and UAC auto-elevation across
        // the WSL→Windows boundary is unreliable (it can just flash a window and
        // fail) — so the manual steps above are printed regardless. But defaulting
        // this confirm to "No" made it too easy to blow past mid-`up` (e.g. right
        // after adding a new component like object storage), silently leaving the
        // NEW host(s) out of a block that still contains the old ones — so only
        // the newly-added host quietly stops resolving in the Windows browser
        // while everything else keeps working, which is a confusing bug to
        // diagnose. Defaulting to Yes means the common case (elevation works)
        // just works; declining still falls back to the manual instructions above.
        //
        // Every non-success path below ALSO returns a one-line summary, not just
        // void — a warning printed once here is easy to lose under the 100+ lines
        // `up` prints afterward (namespace creation, env injection, kustomize
        // apply, restarts, ...). Callers that care (UpCommand) collect these and
        // re-print them as a reminder at the very end of the run, where they're
        // actually still visible.
        if (! confirm('Or have LaraKube try to sync it now via a Windows admin prompt?', true)) {
            return "Windows hosts file for {$appName} wasn't synced — add manually: {$entry}";
        }

        if (! $this->hasWslInterop()) {
            $this->warnWslInteropDown();
            $this->printWindowsHostsManualHelp($entry);

            return "WSL interop is down, so the Windows hosts file for {$appName} wasn't synced — run 'wsl --shutdown' from PowerShell, reopen your terminal, then re-run.";
        }

        if (! $this->syncWindowsHostsFile($updated, $entry)) {
            $this->laraKubeWarn('Could not sync the Windows hosts file automatically.');

            return "Windows hosts file sync failed for {$appName} — add manually: {$entry}";
        }

        $this->laraKubeInfo('Windows hosts file synchronized!');

        return null;
    }

    /**
     * Copy $content into the Windows hosts file via an elevated PowerShell
     * (the standard UAC prompt), returning whether it succeeded. Stages the
     * content and the generated .ps1 driving the copy in randomly-named temp
     * files — hardcoded names in shared /tmp would let a local process race
     * them before the elevated PowerShell reads them (same class of issue as
     * writeToEtcHosts()). Prints the manual fallback itself on any failure,
     * so callers only need to report *why* it failed.
     */
    protected function syncWindowsHostsFile(string $content, string $entry): bool
    {
        $contentTmp = (string) tempnam(sys_get_temp_dir(), 'larakube_win_hosts');
        $scriptTmp = (string) tempnam(sys_get_temp_dir(), 'larakube_win_hosts_sync');
        file_put_contents($contentTmp, $content);

        $winContent = trim(Process::run('wslpath -w '.escapeshellarg($contentTmp))->output());
        if ($winContent === '') {
            @unlink($contentTmp);
            @unlink($scriptTmp);
            $this->printWindowsHostsManualHelp($entry);

            return false;
        }

        file_put_contents(
            $scriptTmp,
            "Copy-Item -LiteralPath '{$winContent}' -Destination 'C:\\Windows\\System32\\drivers\\etc\\hosts' -Force\n",
        );
        $winScript = trim(Process::run('wslpath -w '.escapeshellarg($scriptTmp))->output());

        if ($winScript === '') {
            @unlink($contentTmp);
            @unlink($scriptTmp);
            $this->printWindowsHostsManualHelp($entry);

            return false;
        }

        $startProcess = 'Start-Process -FilePath powershell -Verb RunAs -Wait '
            ."-ArgumentList '-NoProfile','-ExecutionPolicy','Bypass','-File','{$winScript}'";

        // -Wait blocks until the UAC dialog resolves. On multi-monitor setups (or
        // Windows Terminal in general) that dialog can render off the active
        // window/monitor — without this line it just looks like `up` hung.
        $this->line('  <fg=gray>Look for a Windows security prompt (it may be behind this window or on another monitor)...</>');

        $ok = Process::run('powershell.exe -NoProfile -Command '.escapeshellarg($startProcess))->successful();

        @unlink($contentTmp);
        @unlink($scriptTmp);

        // Trust, but verify: Copy-Item reporting success doesn't guarantee the
        // file actually changed — a declined/dismissed UAC prompt or security
        // software that reverts unauthorized hosts-file edits both leave this
        // exit code looking fine while nothing really happened.
        if ($ok) {
            $verify = str_replace("\r\n", "\n", (string) @file_get_contents($this->windowsHostsPath()));
            $ok = str_contains($verify, trim($entry));
        }

        if (! $ok) {
            $this->printWindowsHostsManualHelp($entry);
        }

        return $ok;
    }

    /** Path to the Windows hosts file, as seen from inside WSL2. */
    protected function windowsHostsPath(): string
    {
        return '/mnt/c/Windows/System32/drivers/etc/hosts';
    }

    /**
     * Insert/replace this project's hosts block idempotently. Strips any existing
     * block with the same identifier, then appends a fresh one — so applying it
     * repeatedly with the same entry yields the same file.
     */
    protected function applyHostsBlock(string $current, string $blockIdentifier, string $entryLine): string
    {
        // Remove a previous block: the identifier line + its single entry line.
        // \r?\n (not bare \n) — the WINDOWS hosts file can get re-saved with
        // CRLF by a native editor (Notepad, exactly what printWindowsHostsManualHelp()
        // tells users to use) between syncs. A bare-\n pattern silently fails to
        // match a CRLF block, so the stale block never gets removed and a second,
        // LF-only block gets appended instead — both this method's "needs update"
        // check AND the caller's success message look fine, but Windows resolves
        // hosts top-down, so the untouched stale (now-duplicate) entry keeps
        // winning. This was a real, invisible cause of "we synced it but the old
        // IP/hosts are still what resolves".
        $pattern = '/\r?\n?'.preg_quote($blockIdentifier, '/')."\r?\n[^\r\n]*\r?\n?/";
        $stripped = preg_replace($pattern, "\n", $current);
        $stripped = $stripped ?? $current;

        return rtrim($stripped)."\n\n{$blockIdentifier}\n{$entryLine}\n";
    }

    /**
     * True when dnsmasq is already wildcarding *.{tld} → 127.0.0.1 locally,
     * meaning individual /etc/hosts entries would be redundant.
     */
    protected function dnsmasqCoversKube(?string $tld = null): bool
    {
        $tld = $tld ?? GlobalConfigData::load()->getLocalTld();

        if ($this->isDarwin()) {
            if (! file_exists('/etc/resolver/'.$tld)) {
                return false;
            }
            $result = Process::run('dscacheutil -q host -a name larakube-probe.'.$tld)->output();

            return str_contains($result, '127.0.0.1');
        }

        if (! file_exists('/etc/dnsmasq.d/larakube.conf')) {
            return false;
        }
        $result = Process::run('getent hosts larakube-probe.'.$tld)->output();

        return str_contains($result, '127.0.0.1');
    }

    /**
     * Print copy-pasteable instructions for adding the Windows hosts entry by
     * hand — the fallback when the user declines or the elevated sync fails.
     */
    protected function printWindowsHostsManualHelp(string $entry): void
    {
        $this->line('  👉 Add this line to your Windows hosts file manually:');
        $this->line("     <fg=blue>$entry</>");
        $this->line('     <fg=gray>File: C:\\Windows\\System32\\drivers\\etc\\hosts (open Notepad as Administrator)</>');
        $this->line('');
    }
}
