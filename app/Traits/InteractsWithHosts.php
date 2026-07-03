<?php

namespace App\Traits;

use App\Data\GlobalConfigData;
use App\Enums\SharedClusterService;

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
        $lbIp = trim((string) shell_exec(
            "kubectl get svc traefik -n traefik -o jsonpath='{.status.loadBalancer.ingress[0].ip}' 2>/dev/null",
        ));
        if ($lbIp !== '') {
            return $lbIp;
        }

        // Fall back to the node's InternalIP — the canonical routable address
        // for WSL2 / bare-metal k3s where no cloud LoadBalancer IP exists.
        return trim((string) shell_exec(
            "kubectl get nodes -o jsonpath='{.items[0].status.addresses[?(@.type==\"InternalIP\")].address}' 2>/dev/null",
        )) ?: '127.0.0.1';
    }

    /**
     * Check and optionally update the hosts file(s) based on project context.
     * On WSL this also syncs the Windows hosts file, since the Windows browser
     * doesn't read WSL's /etc/hosts.
     *
     * @param  array  $customHosts  Optional specific hosts to map. If empty, uses the current project's hosts.
     * @param  string|null  $customAppName  Optional app name to group the block. Defaults to the current directory name.
     */
    protected function ensureHostsAreSet(array $customHosts = [], ?string $customAppName = null): void
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
                return;
            }

            $requiredHosts = array_keys($config->getAllHosts('local'));
        } else {
            $requiredHosts = $customHosts;
        }

        if (empty($requiredHosts)) {
            return;
        }

        // If dnsmasq is already wildcarding this project's TLD (its own pinned
        // override, or the developer's global default), individual /etc/hosts
        // entries are redundant — skip the prompt entirely.
        // On WSL2 dnsmasq runs inside the VM and is invisible to Windows browsers,
        // so we always fall through to the Windows hosts file sync instead.
        $tld = $config?->getLocalTld() ?? GlobalConfigData::load()->getLocalTld();

        if (! $this->isWsl() && $this->dnsmasqCoversKube($tld)) {
            return;
        }

        // dnsmasq is set up but doesn't know about this TLD yet — offer to extend it.
        // Skip on WSL2: dnsmasq inside the VM doesn't help Windows browsers.
        if (! $this->isWsl()
            && $this->isDnsmasqInstalled()
            && confirm("dnsmasq is set up but doesn't cover .{$tld} yet — extend it to cover this project too? (requires sudo)")
        ) {
            $this->configureDnsmasq($tld);

            return;
        }

        // 🪟 WSL: the Windows browser can't see WSL's /etc/hosts, so also sync
        // the Windows hosts file. Done first/independently of the Linux sync.
        if ($this->isWsl()) {
            $this->ensureWindowsHostsAreSet($requiredHosts, $appName);
        }

        $externalIp = $this->resolveIngressIp();
        $hostList = implode(' ', $requiredHosts);
        $newEntry = "$externalIp $hostList";
        $blockIdentifier = "# LaraKube: $appName";
        $fullBlock = "\n{$blockIdentifier}\n{$newEntry}\n";

        // 1. Check if update is actually needed
        if (! file_exists('/etc/hosts')) {
            return;
        }

        // If running inside a container, we usually can't update the host's /etc/hosts
        // without mapping it, which is risky. We'll skip it and warn the user.
        if (getenv('LARAKUBE_HOST_PROJECT_PATH') && ! is_writable('/etc/hosts')) {
            $this->warning('Running inside LaraKube daemon: skipping /etc/hosts sync.');
            $this->line('  👉 Please ensure your host machine has these mappings:');
            $this->line("     $newEntry");
            $this->line('');

            return;
        }

        $currentHosts = file_get_contents('/etc/hosts');

        if (str_contains($currentHosts, $fullBlock)) {
            return;
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

            $this->withSpin('Syncing /etc/hosts...', function () use ($currentHosts, $blockIdentifier, $newEntry) {
                $newHosts = $this->applyHostsBlock($currentHosts, $blockIdentifier, $newEntry);

                $tmpPath = sys_get_temp_dir().'/larakube_hosts';
                file_put_contents($tmpPath, $newHosts);

                exec("sudo cp $tmpPath /etc/hosts");
                @unlink($tmpPath);

                return true;
            });

            $this->laraKubeInfo('Hosts synchronized successfully!');
        }
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
            if ($probe !== null && trim((string) shell_exec("kubectl get {$probe} --no-headers 2>/dev/null")) === '') {
                continue;
            }

            $hosts[] = $service->host($domain);
        }

        if ($hosts === []) {
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

        $tmpPath = sys_get_temp_dir().'/larakube_hosts';
        file_put_contents($tmpPath, $updated);
        exec("sudo cp $tmpPath /etc/hosts");
        @unlink($tmpPath);
    }

    /**
     * Write cluster-wide shared service hosts to the Windows hosts file from WSL.
     * Idempotent: skips if already up to date.
     *
     * @param  array<int, string>  $hosts
     */
    protected function syncWindowsHosts(array $hosts, string $appName): void
    {
        $winHosts = '/mnt/c/Windows/System32/drivers/etc/hosts';
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

        $contentTmp = sys_get_temp_dir().'/larakube_win_hosts';
        $scriptTmp = sys_get_temp_dir().'/larakube_win_hosts_sync.ps1';
        file_put_contents($contentTmp, $updated);

        $winContent = trim((string) shell_exec('wslpath -w '.escapeshellarg($contentTmp).' 2>/dev/null'));
        if ($winContent === '') {
            @unlink($contentTmp);
            $this->printWindowsHostsManualHelp($entry);

            return;
        }

        file_put_contents(
            $scriptTmp,
            "Copy-Item -LiteralPath '{$winContent}' -Destination 'C:\\Windows\\System32\\drivers\\etc\\hosts' -Force\n",
        );
        $winScript = trim((string) shell_exec('wslpath -w '.escapeshellarg($scriptTmp).' 2>/dev/null'));

        if ($winScript === '') {
            @unlink($contentTmp);
            @unlink($scriptTmp);
            $this->printWindowsHostsManualHelp($entry);

            return;
        }

        $startProcess = 'Start-Process -FilePath powershell -Verb RunAs -Wait '
            ."-ArgumentList '-NoProfile','-ExecutionPolicy','Bypass','-File','{$winScript}'";

        $output = [];
        $code = 0;
        exec('powershell.exe -NoProfile -Command '.escapeshellarg($startProcess).' 2>/dev/null', $output, $code);

        @unlink($contentTmp);
        @unlink($scriptTmp);

        if ($code !== 0) {
            $this->laraKubeWarn("Could not sync the Windows hosts file automatically for {$appName}.");
            $this->printWindowsHostsManualHelp($entry);
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
    protected function ensureWindowsHostsAreSet(array $requiredHosts, string $appName): void
    {
        $winHosts = '/mnt/c/Windows/System32/drivers/etc/hosts';

        if (! file_exists($winHosts)) {
            return; // Non-standard WSL mount; nothing we can safely do.
        }

        $blockIdentifier = "# LaraKube: $appName";
        $ingressIp = $this->resolveIngressIp();
        $entry = "{$ingressIp} ".implode(' ', $requiredHosts);

        $current = (string) file_get_contents($winHosts);
        $updated = $this->applyHostsBlock($current, $blockIdentifier, $entry);

        // Already in sync (ignoring trailing-whitespace differences).
        if (rtrim($updated) === rtrim($current)) {
            return;
        }

        $this->laraKubeInfo('Windows hosts file needs updating (so your Windows browser resolves these).');
        $this->printWindowsHostsManualHelp($entry);

        // Editing the Windows hosts file needs admin, and UAC auto-elevation across
        // the WSL→Windows boundary is unreliable (it can just flash a window and
        // fail). So the manual steps above are the recommended path, and the
        // auto-sync is strictly opt-in — we no longer rely on the elevation.
        if (! confirm('Or have LaraKube try to sync it now via a Windows admin prompt?', false)) {
            return;
        }

        if (! $this->hasWslInterop()) {
            $this->warnWslInteropDown();
            $this->printWindowsHostsManualHelp($entry);

            return;
        }

        // Write the full new content to a temp file, then copy it into place via
        // an elevated PowerShell running a generated .ps1 (literal paths only —
        // no fragile inline quoting).
        $contentTmp = sys_get_temp_dir().'/larakube_win_hosts';
        $scriptTmp = sys_get_temp_dir().'/larakube_win_hosts_sync.ps1';
        file_put_contents($contentTmp, $updated);

        $winContent = trim((string) shell_exec('wslpath -w '.escapeshellarg($contentTmp).' 2>/dev/null'));
        if ($winContent === '') {
            @unlink($contentTmp);
            $this->printWindowsHostsManualHelp($entry);

            return;
        }

        file_put_contents(
            $scriptTmp,
            "Copy-Item -LiteralPath '{$winContent}' -Destination 'C:\\Windows\\System32\\drivers\\etc\\hosts' -Force\n",
        );
        $winScript = trim((string) shell_exec('wslpath -w '.escapeshellarg($scriptTmp).' 2>/dev/null'));

        if ($winScript === '') {
            @unlink($contentTmp);
            @unlink($scriptTmp);
            $this->printWindowsHostsManualHelp($entry);

            return;
        }

        $startProcess = 'Start-Process -FilePath powershell -Verb RunAs -Wait '
            ."-ArgumentList '-NoProfile','-ExecutionPolicy','Bypass','-File','{$winScript}'";

        $output = [];
        $code = 0;
        exec('powershell.exe -NoProfile -Command '.escapeshellarg($startProcess).' 2>/dev/null', $output, $code);

        @unlink($contentTmp);
        @unlink($scriptTmp);

        if ($code !== 0) {
            $this->laraKubeWarn('Could not sync the Windows hosts file automatically.');
            $this->printWindowsHostsManualHelp($entry);

            return;
        }

        $this->laraKubeInfo('Windows hosts file synchronized!');
    }

    /**
     * Insert/replace this project's hosts block idempotently. Strips any existing
     * block with the same identifier, then appends a fresh one — so applying it
     * repeatedly with the same entry yields the same file.
     */
    protected function applyHostsBlock(string $current, string $blockIdentifier, string $entryLine): string
    {
        // Remove a previous block: the identifier line + its single entry line.
        $pattern = '/\n?'.preg_quote($blockIdentifier, '/')."\n[^\n]*\n?/";
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
            $result = shell_exec('dscacheutil -q host -a name larakube-probe.'.$tld.' 2>/dev/null');

            return $result !== null && str_contains((string) $result, '127.0.0.1');
        }

        if (! file_exists('/etc/dnsmasq.d/larakube.conf')) {
            return false;
        }
        $result = shell_exec('getent hosts larakube-probe.'.$tld.' 2>/dev/null');

        return $result !== null && str_contains((string) $result, '127.0.0.1');
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
