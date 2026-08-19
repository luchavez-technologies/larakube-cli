<?php

namespace App\Traits;

use App\Data\GlobalConfigData;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

trait InteractsWithTrust
{
    use DetectsWsl, InteractsWithOs, LaraKubeOutput, ManagesLocalCa, StreamsProcessOutput;

    protected function installCaToKeychain(string $caPath): int
    {
        $caTemporaryDirectory = (new TemporaryDirectory)->permission(0700)->deleteWhenDestroyed()->create();
        $tmpCa = $caTemporaryDirectory->path().'/larakube-ca.crt';
        file_put_contents($tmpCa, file_get_contents($caPath));

        $os = PHP_OS_FAMILY;

        if ($this->isWsl()) {
            @mkdir(home_path('.larakube'), 0755, true);
            $caFile = home_path('.larakube/larakube-local-ca.crt');
            file_put_contents($caFile, file_get_contents($caPath));
            $caTemporaryDirectory->delete();

            if (! $this->hasWslInterop()) {
                $this->warnWslInteropDown();

                return 1;
            }

            // certutil.exe cannot read \\wsl.localhost\ UNC paths, so stage the cert
            // in the Windows %TEMP% directory which is always reachable by Windows processes.
            $winTempDir = trim(Process::run('cmd.exe /c "echo %TEMP%"')->output());
            $wslTempDir = $winTempDir !== ''
                ? trim(Process::run('wslpath '.escapeshellarg($winTempDir))->output())
                : '';

            // Permanent Windows-visible path (used in fallback instructions if certutil fails).
            $permanentWinPath = trim(Process::run('wslpath -w '.escapeshellarg($caFile))->output());

            if ($wslTempDir !== '' && is_dir($wslTempDir)) {
                $stagePath = $wslTempDir.'/larakube-local-ca.crt';
                copy($caFile, $stagePath);
                $certutilPath = trim(Process::run('wslpath -w '.escapeshellarg($stagePath))->output());
            } else {
                $stagePath = null;
                $certutilPath = $permanentWinPath;
            }

            passthru('certutil.exe -user -addstore -f "Root" '.escapeshellarg($certutilPath), $code);

            if ($stagePath !== null) {
                @unlink($stagePath);
            }

            $this->line('');
            if ($code !== 0) {
                $this->laraKubeWarn('Could not add the CA to the Windows user store automatically.');
                $this->line('  👉 Re-run <fg=cyan>larakube trust</> from a <fg=cyan;options=bold>PowerShell / Windows Terminal opened as Administrator</> (right-click → Run as administrator),');
                $this->line('     or in that elevated Windows terminal run:');
                $this->line("       certutil -addstore -f Root \"{$permanentWinPath}\"");
                $this->line('     …or double-click that .crt → Install Certificate → Local Machine → Trusted Root Certification Authorities.');

                return 1;
            }

            $this->laraKubeInfo('✅ CA added to the Windows current-user store. Restart your browser.');
            $this->line('  <fg=yellow>🪟 Windows note:</> if HTTPS still shows a warning, the current-user store wasn\'t enough on your setup —');
            $this->line('     re-run <fg=cyan>larakube trust</> from a <fg=cyan;options=bold>PowerShell / Windows Terminal opened as Administrator</> so the CA registers machine-wide.');
            $this->line('  <fg=gray>Firefox uses its own trust store — import the CA there separately if needed.</>');

            return 0;
        }

        if ($this->isDarwin()) {
            $this->info('  🔒 Installing to macOS System Keychain...');
            passthru('sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain '.escapeshellarg($tmpCa));
        } elseif ($this->isLinux()) {
            if (file_exists('/usr/local/share/ca-certificates/')) {
                $this->info('  🔒 Installing to ca-certificates (Debian/Ubuntu)...');
                passthru('sudo cp '.escapeshellarg($tmpCa).' /usr/local/share/ca-certificates/larakube-local-ca.crt');
                passthru('sudo update-ca-certificates');
            } elseif (file_exists('/etc/pki/ca-trust/source/anchors/')) {
                $this->info('  🔒 Installing to ca-trust (Fedora/RHEL)...');
                passthru('sudo cp '.escapeshellarg($tmpCa).' /etc/pki/ca-trust/source/anchors/larakube-local-ca.crt');
                passthru('sudo update-ca-trust extract');
            }
        } else {
            $this->warn("  ⚠ Automatic trust installation not supported for {$os}.");
            $this->info("  👉 Manually trust: {$caPath}");
        }

        $caTemporaryDirectory->delete();

        return 0;
    }

    protected function removeCaFromKeychain(): void
    {
        $os = PHP_OS_FAMILY;

        if ($this->isWsl()) {
            if (! $this->hasWslInterop()) {
                $this->warnWslInteropDown();

                return;
            }

            $this->info('  🪟 WSL2 detected. Removing from Windows Root Store...');
            passthru('certutil.exe -delstore "Root" "LaraKube Local CA"');

            return;
        }

        if ($this->isDarwin()) {
            $this->info('  🔓 macOS detected. Removing from System Keychain...');
            $caPath = $this->getLocalCaCertPath();

            if (file_exists($caPath)) {
                $temporaryDirectory = (new TemporaryDirectory)->permission(0700)->deleteWhenDestroyed()->create();
                $tmpCa = $temporaryDirectory->path().'/larakube-untrust.crt';
                file_put_contents($tmpCa, file_get_contents($caPath));
                $fingerprint = trim(Process::run("openssl x509 -noout -fingerprint -sha1 -in {$tmpCa} | cut -d'=' -f2 | sed 's/://g'")->output());
                $temporaryDirectory->delete();

                if ($fingerprint) {
                    passthru("sudo security delete-certificate -Z {$fingerprint} /Library/Keychains/System.keychain 2>/dev/null || sudo security delete-certificate -c \"LaraKube Local CA\" /Library/Keychains/System.keychain");
                } else {
                    passthru('sudo security delete-certificate -c "LaraKube Local CA" /Library/Keychains/System.keychain');
                }
            } else {
                passthru('sudo security delete-certificate -c "LaraKube Local CA" /Library/Keychains/System.keychain');
            }
        } elseif ($this->isLinux()) {
            if (file_exists('/usr/local/share/ca-certificates/larakube-local-ca.crt')) {
                $this->info('  🔓 Linux (Debian/Ubuntu) detected. Removing ca-certificate...');
                passthru('sudo rm -f /usr/local/share/ca-certificates/larakube-local-ca.crt');
                passthru('sudo update-ca-certificates --fresh');
            } elseif (file_exists('/etc/pki/ca-trust/source/anchors/larakube-local-ca.crt')) {
                $this->info('  🔓 Linux (Fedora/RHEL) detected. Removing ca-trust...');
                passthru('sudo rm -f /etc/pki/ca-trust/source/anchors/larakube-local-ca.crt');
                passthru('sudo update-ca-trust extract');
            }
        } else {
            $this->warn("  ⚠ Automatic trust removal is not supported for {$os}.");
        }
    }

    protected function isDnsmasqInstalled(): bool
    {
        return trim(Process::run('which dnsmasq')->output()) !== '';
    }

    /**
     * dnsmasq is required (not opt-in) on macOS and Linux — wildcard *.{tld}
     * resolution is what makes every host "just work" without per-hostname
     * /etc/hosts entries, and every platform benefits equally from it.
     *
     * WSL2 is the one exception: dnsmasq runs inside the WSL2 VM, invisible
     * to the Windows browser that actually renders pages, so it would do
     * nothing useful there — WSL2's host resolution problem is entirely on
     * the Windows side (see ensureWindowsHostsAreSet()), not this method.
     */
    protected function setupDnsmasq(): void
    {
        if ($this->isWsl()) {
            return;
        }

        if (! $this->isDnsmasqInstalled()) {
            $this->laraKubeInfo('Installing dnsmasq for automatic wildcard DNS resolution...');

            if (! $this->installDnsmasq()) {
                $this->laraKubeWarn('Could not install dnsmasq — larakube up will fall back to managing /etc/hosts entries per-hostname instead.');

                return;
            }
        }

        $this->configureDnsmasq();
    }

    protected function installDnsmasq(): bool
    {
        $os = PHP_OS_FAMILY;

        if ($os === 'Darwin') {
            $brewBin = trim(Process::run('which brew')->output());
            if ($brewBin === '') {
                $this->warn('  Homebrew not found. Install it from https://brew.sh then run: brew install dnsmasq');

                return false;
            }

            return $this->runStreaming('brew install dnsmasq') === 0;
        }

        if ($os === 'Linux') {
            if (file_exists('/usr/bin/apt-get')) {
                passthru('sudo apt-get install -y dnsmasq', $code);
            } elseif (file_exists('/usr/bin/dnf')) {
                passthru('sudo dnf install -y dnsmasq', $code);
            } else {
                $this->warn('  Could not detect package manager. Install dnsmasq manually.');

                return false;
            }

            return $code === 0;
        }

        $this->warn('  dnsmasq install not supported on this platform.');

        return false;
    }

    /** Path to LaraKube's dnsmasq conf drop-in for the current platform, or null if unsupported. */
    protected function getDnsmasqConfPath(): ?string
    {
        if ($this->isDarwin()) {
            $brewPrefix = trim(Process::run('brew --prefix')->output()) ?: '/usr/local';

            return $brewPrefix.'/etc/dnsmasq.d/larakube.conf';
        }

        if ($this->isLinux()) {
            return '/etc/dnsmasq.d/larakube.conf';
        }

        return null;
    }

    /** Every TLD already wildcarded in an existing dnsmasq conf's content. */
    protected function parseDnsmasqTlds(string $confContent): array
    {
        preg_match_all('/^address=\/\.([^\/]+)\/127\.0\.0\.1$/m', $confContent, $matches);

        return $matches[1] ?? [];
    }

    /** dnsmasq conf content wildcarding every given TLD to 127.0.0.1. */
    protected function buildDnsmasqConf(array $tlds): string
    {
        $lines = array_map(fn (string $tld) => "address=/.{$tld}/127.0.0.1", array_values(array_unique($tlds)));

        // bind-interfaces + listen-address=127.0.0.1 prevents dnsmasq from trying to
        // bind on OrbStack/Docker bridge interfaces (which fail with Permission denied).
        return "listen-address=127.0.0.1\nbind-interfaces\n".implode("\n", $lines)."\n";
    }

    /**
     * Ensure dnsmasq wildcards $tld (defaulting to the global TLD) to 127.0.0.1,
     * in addition to every TLD it already covers — never replacing or removing an
     * existing entry. Multiple projects can pin different TLDs via `config:tld
     * --project`, and each one that opts into dnsmasq coverage should keep working
     * even after another project (or the global default) changes its TLD.
     * No-ops (no sudo, no restart) if $tld is already fully covered.
     */
    protected function configureDnsmasq(?string $tld = null): void
    {
        $tld = $tld ?? GlobalConfigData::load()->getLocalTld();
        $confPath = $this->getDnsmasqConfPath();

        if ($confPath === null) {
            return;
        }

        $existingConf = file_exists($confPath) ? (string) file_get_contents($confPath) : '';
        $existingTlds = $this->parseDnsmasqTlds($existingConf);
        $tlds = array_unique(array_merge($existingTlds, [$tld]));

        $resolverMissing = $this->isDarwin() && ! file_exists('/etc/resolver/'.$tld);

        if (in_array($tld, $existingTlds, true) && ! $resolverMissing) {
            return; // already covered — no sudo, no restart needed
        }

        $conf = $this->buildDnsmasqConf($tlds);

        if ($this->isDarwin()) {
            @mkdir(dirname($confPath), 0755, true);
            file_put_contents($confPath, $conf);

            passthru('sudo mkdir -p /etc/resolver');
            foreach ($tlds as $coveredTld) {
                if (file_exists('/etc/resolver/'.$coveredTld)) {
                    continue;
                }
                $temporaryDirectory = (new TemporaryDirectory)->permission(0700)->deleteWhenDestroyed()->create();
                $tmpResolver = $temporaryDirectory->path().'/resolver';
                file_put_contents($tmpResolver, "nameserver 127.0.0.1\n");
                passthru('sudo cp '.escapeshellarg($tmpResolver).' /etc/resolver/'.escapeshellarg($coveredTld));
                $temporaryDirectory->delete();
            }

            // Stop any user-level instance first (can't bind port 53 without root).
            // Suppress output — it's fine if there's nothing to stop.
            Process::run('brew services stop dnsmasq');
            // Must run as root so dnsmasq can bind port 53.
            passthru('sudo brew services restart dnsmasq');
        } else {
            $temporaryDirectory = (new TemporaryDirectory)->permission(0700)->deleteWhenDestroyed()->create();
            $tmpConf = $temporaryDirectory->path().'/dnsmasq.conf';
            file_put_contents($tmpConf, $conf);
            passthru('sudo mkdir -p /etc/dnsmasq.d');
            passthru('sudo cp '.escapeshellarg($tmpConf).' '.escapeshellarg($confPath));
            $temporaryDirectory->delete();
            passthru('sudo systemctl restart dnsmasq');
        }

        $covered = implode(', ', array_map(fn (string $t) => "*.{$t}", $tlds));
        $this->laraKubeInfo("dnsmasq configured: {$covered} → 127.0.0.1");
    }

    /**
     * Explain that WSL can't currently exec Windows binaries (certutil.exe,
     * powershell.exe, ...) and how to fix it, instead of letting a bare
     * "Exec format error" from the shell reach the user.
     */
    protected function warnWslInteropDown(): void
    {
        $this->line('');
        $this->laraKubeWarn('WSL cannot currently launch Windows executables (interop is down).');
        $this->line('  👉 From <fg=cyan;options=bold>PowerShell</> (not WSL), close all WSL terminals and run:');
        $this->line('       wsl --shutdown');
        $this->line('     then reopen your WSL terminal and re-run this command.');
        $this->line('  <fg=gray>This can happen after switching the WSL default distro or a Windows sleep/hibernate — a full VM restart re-registers interop.</>');
    }

    protected function isCaTrusted(): bool
    {
        if ($this->isWsl()) {
            return str_contains(Process::run('certutil.exe -user -verifystore Root "LaraKube Local CA"')->output(), 'LaraKube Local CA');
        }

        if ($this->isDarwin()) {
            return Process::run('security find-certificate -c "LaraKube Local CA" /Library/Keychains/System.keychain')->output() !== '';
        }

        if ($this->isLinux()) {
            return file_exists('/usr/local/share/ca-certificates/larakube-local-ca.crt')
                || file_exists('/etc/pki/ca-trust/source/anchors/larakube-local-ca.crt');
        }

        return false;
    }

    protected function isDnsmasqConfigured(): bool
    {
        $tld = GlobalConfigData::load()->getLocalTld();

        if ($this->isDarwin()) {
            $brewPrefix = trim(Process::run('brew --prefix')->output()) ?: '/usr/local';

            if (! file_exists($brewPrefix.'/etc/dnsmasq.d/larakube.conf') || ! file_exists('/etc/resolver/'.$tld)) {
                return false;
            }
        } elseif ($this->isLinux()) {
            if (! file_exists('/etc/dnsmasq.d/larakube.conf')) {
                return false;
            }
        } else {
            return false;
        }

        // Files exist — verify dnsmasq is actually running by probing DNS.
        if ($this->isDarwin()) {
            $result = Process::run('dscacheutil -q host -a name larakube-probe.'.$tld)->output();

            return str_contains($result, '127.0.0.1');
        }

        // Linux: use getent which respects /etc/resolv.conf + nsswitch
        $result = Process::run('getent hosts larakube-probe.'.$tld)->output();

        return str_contains($result, '127.0.0.1');
    }

    /**
     * Structured pass/fail results for the local HTTPS trust chain (CA,
     * keychain, DNS, system + app certs) — the single source of truth so
     * `trust:check`'s detailed per-line report and `doctor`'s summarized
     * issue list can't drift out of sync with each other.
     *
     * @return array<int, array{ok: bool, section: string, label: string, fix: ?string}>
     */
    protected function diagnoseTrustChain(): array
    {
        $items = [];
        $tld = GlobalConfigData::load()->getLocalTld();

        // Local CA
        $caExists = $this->localCaExists();
        $items[] = [
            'ok' => $caExists,
            'section' => 'Local CA',
            'label' => 'CA files present at ~/.larakube/certificates/',
            'fix' => $caExists ? null : 'larakube trust',
        ];

        if ($caExists) {
            $trusted = $this->isCaTrusted();
            $items[] = [
                'ok' => $trusted,
                'section' => 'Local CA',
                'label' => 'Trusted in system keychain',
                'fix' => $trusted ? null : 'larakube trust',
            ];
        }

        // DNS
        $dnsSection = "DNS (*.{$tld} → 127.0.0.1)";
        if ($this->isDnsmasqConfigured()) {
            $items[] = ['ok' => true, 'section' => $dnsSection, 'label' => 'dnsmasq configured', 'fix' => null];
        } else {
            $hostsHasKube = str_contains((string) file_get_contents('/etc/hosts'), '# LaraKube:');
            $items[] = [
                'ok' => $hostsHasKube,
                'section' => $dnsSection,
                'label' => '/etc/hosts fallback active (run larakube up to add entries)',
                'fix' => $hostsHasKube ? null : 'larakube trust  (to set up dnsmasq) or  larakube up  (to add /etc/hosts entries)',
            ];
        }

        // System cert
        $sysSection = "System cert (console.{$tld}, traefik.{$tld}, …)";
        $sysCrt = $this->getSystemCertPath();
        $sysKey = $this->getSystemKeyPath();

        if (! file_exists($sysCrt) || ! file_exists($sysKey)) {
            $items[] = ['ok' => false, 'section' => $sysSection, 'label' => 'System cert not found', 'fix' => 'larakube traefik:setup'];
        } elseif (! $this->certIsValid($sysCrt)) {
            $items[] = ['ok' => false, 'section' => $sysSection, 'label' => 'System cert expired or expiring within 30 days', 'fix' => 'larakube trust:reset'];
        } elseif (! $this->certCoversHost($sysCrt, "console.{$tld}")) {
            $items[] = ['ok' => false, 'section' => $sysSection, 'label' => 'System cert covers wrong TLD (needs regeneration)', 'fix' => 'larakube trust:reset'];
        } else {
            $items[] = ['ok' => true, 'section' => $sysSection, 'label' => "Valid until {$this->certExpiryDate($sysCrt)}", 'fix' => null];
        }

        // App certs
        foreach ($this->getAllLocalAppCerts() as $appName => $paths) {
            $crt = $paths['crt'];
            // Each app's own pinned TLD (sidecar written alongside its cert), not
            // the global $tld — a project with a `config:tld --project` override
            // legitimately uses a different TLD than this machine's default.
            $appTld = $this->getAppCertTld($appName);

            if (! $this->certIsValid($crt)) {
                $items[] = [
                    'ok' => false,
                    'section' => 'App certs',
                    'label' => sprintf('  %-18s expired or expiring — run: larakube up', $appName),
                    'fix' => 'larakube up',
                ];
            } elseif (! $this->certCoversHost($crt, "{$appName}.{$appTld}")) {
                $items[] = [
                    'ok' => false,
                    'section' => 'App certs',
                    'label' => sprintf('  %-18s wrong TLD — run: larakube up (in that project)', $appName),
                    'fix' => 'larakube up (in that project)',
                ];
            } else {
                $items[] = [
                    'ok' => true,
                    'section' => 'App certs',
                    'label' => sprintf('  %-18s valid until %s (.%s)', $appName, $this->certExpiryDate($crt), $appTld),
                    'fix' => null,
                ];
            }
        }

        return $items;
    }

    /** Cert expiry as a Y-m-d date, or 'unknown' if the cert can't be read/parsed. */
    protected function certExpiryDate(string $crtPath): string
    {
        $ts = $this->getCertExpiry($crtPath);

        return $ts ? date('Y-m-d', $ts) : 'unknown';
    }
}
