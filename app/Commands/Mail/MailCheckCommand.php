<?php

namespace App\Commands\Mail;

use App\Data\ConfigData;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithMail;
use App\Traits\InteractsWithStalwartApi;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;

/**
 * One command to answer "is my mail server actually working?" — it runs the
 * whole diagnostic checklist (pod, external port reachability, DNS + deliverability
 * records) that otherwise lives in a wall of manual runbook steps, and prints a
 * fix hint for anything that isn't green. DNS is queried against a public resolver
 * (1.1.1.1) so a stale local cache can't produce a misleading result.
 */
class MailCheckCommand extends Command
{
    use InteractsWithClusterContext, InteractsWithMail, InteractsWithStalwartApi, LaraKubeOutput;

    protected $signature = 'mail:check
        {environment? : Environment to check — "local" (default) or cloud.}
        {--context=  : Target a specific kube-context}
        {--env=      : Legacy alias for the environment}';

    protected $description = 'Health-check the mail server (pod, ports, DNS, deliverability) with fix hints';

    private int $pass = 0;

    private int $warn = 0;

    private int $fail = 0;

    public function handle(): int
    {
        $this->renderHeader();

        $env = $this->resolveEnvironment();

        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;

        $context = (string) $this->option('context') ?: null;
        if (! $context && $config && $env !== 'local') {
            $context = $this->environmentContextOrCurrent($config, $env);
        }

        $kubectl = $this->mailKubectl($context);
        $ns = $this->mailNamespace();
        $host = (string) $this->resolveMailHostReadOnly($env, $config);

        if ($host === '') {
            $this->laraKubeError("No mail host configured for '{$env}'. Run `larakube mail:init {$env}` first.");

            return 1;
        }
        $domain = $this->mailCheckDomain($host);

        $this->laraKubeInfo("Checking Stalwart at {$host}  ({$env})");
        $this->newLine();

        // --- Cluster -------------------------------------------------------
        $ready = trim(Process::run(
            "{$kubectl} get statefulset stalwart -n {$ns} -o jsonpath='{.status.readyReplicas}'",
        )->output());
        $this->report($ready === '1' ? 'ok' : 'fail', 'Mail server pod is running',
            "Deploy it: larakube mail:init {$env}");

        // --- Admin console -------------------------------------------------
        $code = $this->httpStatus("https://{$host}/admin");
        $this->report(($code >= 200 && $code < 400) ? 'ok' : 'fail',
            "Admin console reachable (https://{$host}/admin)",
            $code === 0
                ? 'Could not connect — DNS/firewall, or a stale local DNS cache (flush it / try Incognito).'
                : "Returned HTTP {$code} — if you just finished setup, run `larakube mail:restart {$env}`.");

        // --- DNS (public resolver, so a stale local cache never lies) ------
        $ip = $this->dig($host, 'A')[0] ?? null;
        $this->report($ip ? 'ok' : 'fail', "DNS · A record for {$host}".($ip ? " → {$ip}" : ''),
            'ExternalDNS should create this — check your Cloudflare token / `larakube dns:init`.');

        $mxOk = $this->digHasTarget($domain, 'MX', $host);
        $this->report($mxOk ? 'ok' : 'fail', "DNS · MX for {$domain} → {$host}",
            "Add it: {$domain}  MX  10  {$host}   (external inbound mail needs this).");

        $this->report($this->digHas($domain, 'TXT', 'v=spf1') ? 'ok' : 'warn', "DNS · SPF for {$domain}",
            "Publish: {$domain}  TXT  \"v=spf1 mx ~all\".");

        $this->report($this->digHas('_dmarc.'.$domain, 'TXT', 'v=DMARC1') ? 'ok' : 'warn', "DNS · DMARC for {$domain}",
            "Publish: _dmarc.{$domain}  TXT  \"v=DMARC1; p=quarantine; rua=mailto:postmaster@{$domain}\".");

        // --- Mail ports (from this machine, against the public IP) ---------
        $target = $ip ?: $host;
        $this->newLine();
        $this->laraKubeLine('  <fg=gray>Mail ports (reachability from here):</>');
        $ports = [
            25 => ['SMTP (inbound MX)', 'warn', 'Needed to receive external mail. A fail here can also be YOUR network blocking outbound 25.'],
            465 => ['Submissions / SSL', 'fail', 'Clients send through 465. mail:init opens it on both firewall layers — check the firewall.'],
            587 => ['Submission / STARTTLS', 'warn', 'Optional — LaraKube uses 465 (implicit TLS) everywhere. Add a 587 listener only if a client specifically needs STARTTLS.'],
            993 => ['IMAPS', 'fail', 'Clients read mail on 993. mail:init opens it — check the firewall.'],
            4190 => ['ManageSieve', 'warn', 'Optional — server-side mail filters.'],
        ];
        foreach ($ports as $port => [$label, $failSeverity, $hint]) {
            $open = $this->tcpOpen($target, (int) $port);
            $this->report($open ? 'ok' : $failSeverity, "Port {$port} · {$label}", $hint);
        }

        // --- Outbound relay (external delivery) ----------------------------
        // A fresh cloud IP can't deliver to the internet directly — DigitalOcean
        // (and most clouds) block outbound port 25. Without a relay, mail to
        // Gmail/etc. queues and eventually bounces; only internal + inbound work.
        // When a relay IS configured we don't just check the secret exists — we
        // reach through the pod and actually SMTP-AUTH against the relay, because
        // a blocked submission port or a wrong login/key is silent otherwise.
        $this->newLine();
        $relayOn = trim(Process::run(
            "{$kubectl} get secret mail-relay -n {$ns} --ignore-not-found -o name",
        )->output()) !== '';

        if (! $relayOn) {
            $this->report('warn', 'Outbound relay for external mail',
                'NOT set up — DigitalOcean blocks outbound port 25, so mail to Gmail/external addresses will queue but never deliver. Configure one: larakube mail:relay. (Internal + inbound mail work without it.)');
        } else {
            [$status, $where, $hint] = $this->probeRelay($kubectl, $ns);
            $this->report($status, "Outbound relay for external mail{$where}", $hint);
        }

        // --- DKIM (selector is per-domain; point them at the UI) -----------
        $this->newLine();
        $this->laraKubeLine("  <fg=gray>DKIM · check admin → Domains → {$domain} → DKIM, and publish the selector TXT it shows.</>");

        // --- Summary -------------------------------------------------------
        $this->newLine();
        $total = $this->pass + $this->warn + $this->fail;
        if ($this->fail === 0 && $this->warn === 0) {
            $this->laraKubeInfo("✅ All {$total} checks passed — mail is fully configured.");
        } elseif ($this->fail === 0) {
            $this->laraKubeInfo("Good: {$this->pass} passed, {$this->warn} warning(s). Review the ⚠ hints above.");
        } else {
            $this->laraKubeError("{$this->fail} failed · {$this->warn} warning(s) · {$this->pass} passed. Fix the ✗ items above.");
        }

        return $this->fail === 0 ? 0 : 1;
    }

    protected function resolveEnvironment(): string
    {
        $explicit = (string) ($this->argument('environment') ?: $this->option('env') ?: '');
        if ($explicit !== '') {
            return $explicit;
        }

        if ($this->option('no-interaction')) {
            return 'local';
        }

        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;

        $envs = $config ? array_merge(['local'], $config->getCloudEnvironments()) : ['local'];

        return select(
            label: 'Which environment is the mail server in?',
            options: array_combine($envs, $envs),
            default: 'local',
        );
    }

    /**
     * Actually exercise the configured relay from inside the Stalwart pod:
     * open the relay's submission port and drive an SMTP AUTH, so a blocked
     * port (DO null-routes 25/465/587) or a wrong login/key surfaces here
     * instead of silently swallowing every outbound message.
     *
     * @return array{0: string, 1: string, 2: string} [status, labelSuffix, hint]
     */
    private function probeRelay(string $kubectl, string $ns): array
    {
        $provider = $this->readNamedSecret($kubectl, $ns, 'mail-relay', 'provider') ?: 'relay';
        $user = (string) $this->readNamedSecret($kubectl, $ns, 'mail-relay', 'username');
        $pass = (string) $this->readNamedSecret($kubectl, $ns, 'mail-relay', 'password');

        // Host/port/TLS come from the Stalwart route itself — that's what
        // actually gets used for delivery (and honors any --port override).
        $route = $this->stalwartFindRoute($kubectl, $ns, $provider);
        if ($route === null) {
            return ['warn', ' (secret present, no Stalwart route)',
                "The mail-relay secret exists but Stalwart has no '{$provider}' route. Re-run: larakube mail:relay {$provider} --env=<env>."];
        }

        $address = (string) ($route['address'] ?? '');
        $port = (int) ($route['port'] ?? 0);
        $implicitTls = (bool) ($route['implicitTls'] ?? false);
        $where = " ({$provider} · {$address}:{$port})";

        if ($address === '' || $port === 0 || $user === '' || $pass === '') {
            return ['warn', $where, 'Relay route is incomplete — re-run larakube mail:relay with --username/--api-key.'];
        }

        $convo = "EHLO larakube\r\nAUTH LOGIN\r\n".base64_encode($user)."\r\n".base64_encode($pass)."\r\nQUIT\r\n";
        $starttls = $implicitTls ? '' : '-starttls smtp ';
        // NO -crlf: the convo already uses \r\n; adding -crlf doubles each to
        // \r\r\n, which strict relays (SES) reject with "501 CR and LF must be
        // CRLF paired" — the probe then sees no 235/535 and false-negatives.
        $script = 'echo '.base64_encode($convo).' | base64 -d | timeout 15 openssl s_client -quiet '
            .$starttls.'-connect '.escapeshellarg($address.':'.$port).' 2>/dev/null';

        $pod = $this->stalwartPodName($kubectl, $ns);
        $out = Process::timeout(30)->run(
            "{$kubectl} exec {$pod} -n {$ns} -- sh -c ".escapeshellarg($script),
        )->output();

        if (str_contains($out, '235')) {
            return ['ok', $where.' — reachable & authenticating', ''];
        }

        if (str_contains($out, '535')) {
            return ['fail', $where.' — credentials rejected (535)',
                'The relay refused the login/key. Brevo: login is the "<id>@smtp-brevo.com" from SMTP & API → SMTP settings (NOT your account email) + the full "xsmtpsib-…" key. SES: the AKIA… SMTP username + SMTP password (NOT your AWS access key). Re-run: larakube mail:relay '.$provider.' --env=<env> --username=… --api-key=…'];
        }

        // No banner / no auth response = the port never opened.
        return ['fail', $where.' — port unreachable from the cluster',
            "Nothing answered on {$address}:{$port}. DigitalOcean null-routes outbound 25/465/587 (above the cloud firewall); use 2525 instead: larakube mail:relay {$provider} --env=<env> --port=2525 (Brevo's default is already 2525)."];
    }

    private function readNamedSecret(string $kubectl, string $ns, string $secret, string $key): ?string
    {
        $out = trim(Process::run(
            "{$kubectl} get secret {$secret} -n {$ns} -o jsonpath='{.data.{$key}}'",
        )->output());

        return $out !== '' ? (string) base64_decode($out) : null;
    }

    private function report(string $status, string $label, string $hint = ''): void
    {
        $icon = match ($status) {
            'ok' => '<fg=green>✓</>',
            'warn' => '<fg=yellow>⚠</>',
            default => '<fg=red>✗</>',
        };
        $this->line("  {$icon} {$label}");
        if ($status !== 'ok' && $hint !== '') {
            $this->line("      <fg=gray>{$hint}</>");
        }

        match ($status) {
            'ok' => $this->pass++,
            'warn' => $this->warn++,
            default => $this->fail++,
        };
    }

    /** HTTP status for a URL (0 on connect failure). Cert is not verified — we're probing reachability. */
    private function httpStatus(string $url): int
    {
        $out = Process::timeout(10)->run(
            'curl -sS -o /dev/null -w "%{http_code}" -k --max-time 7 '.escapeshellarg($url),
        )->output();

        return (int) trim($out);
    }

    /** Can we open a TCP connection to host:port within the timeout? */
    private function tcpOpen(string $host, int $port, int $timeout = 4): bool
    {
        $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if ($fp) {
            fclose($fp);

            return true;
        }

        return false;
    }

    /**
     * Query a public resolver (1.1.1.1) for a record type — bypasses the local
     * DNS cache so results reflect what the world actually sees.
     *
     * @return array<int, string>
     */
    private function dig(string $name, string $type): array
    {
        $out = Process::timeout(8)->run(
            'dig +short +time=3 +tries=2 @1.1.1.1 '.escapeshellarg($type).' '.escapeshellarg($name),
        )->output();

        return array_values(array_filter(array_map('trim', explode("\n", $out))));
    }

    /** True when any record for $name/$type contains $needle (case-insensitive). */
    private function digHas(string $name, string $type, string $needle): bool
    {
        foreach ($this->dig($name, $type) as $line) {
            if (stripos($line, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /** True when an MX/CNAME-style record for $name/$type points at $target. */
    private function digHasTarget(string $name, string $type, string $target): bool
    {
        foreach ($this->dig($name, $type) as $line) {
            // MX lines look like "10 mail.example.com." — match the trailing host.
            $parts = preg_split('/\s+/', trim($line));
            $last = rtrim((string) end($parts), '.');
            if (strcasecmp($last, $target) === 0) {
                return true;
            }
        }

        return false;
    }

    /** Drop the leftmost label ("mail.example.com" → "example.com"). */
    private function mailCheckDomain(string $host): string
    {
        $parts = explode('.', $host);

        return count($parts) > 2 ? implode('.', array_slice($parts, 1)) : $host;
    }
}
