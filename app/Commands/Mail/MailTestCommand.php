<?php

namespace App\Commands\Mail;

use App\Data\ConfigData;
use App\Enums\ClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithMail;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

/**
 * Sends a real test email through Stalwart to prove the send path end-to-end:
 * SMTP AUTH as a real account, then submission of a message to a target address.
 * Driven from inside the pod (localhost:465, implicit TLS) so a blocked outbound
 * port on the operator's network can't produce a false negative. For external
 * recipients it also flags the outbound-relay requirement, so a "queued" result
 * isn't mistaken for "delivered".
 */
class MailTestCommand extends Command
{
    use InteractsWithClusterContext, InteractsWithMail, LaraKubeOutput;

    protected $signature = 'mail:test
        {environment=local : Environment whose mail server to target}
        {--to= : Recipient address to send the test email to}
        {--from=      : Sender — a Stalwart account (defaults to the cached sender / noreply@<domain>)}
        {--password=  : The sender account password (prompted if omitted)}
        {--context=   : Target a specific kube-context}';

    protected $description = 'Send a test email through Stalwart to verify authentication + delivery end-to-end';

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');

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

        if (! $this->isMailInstalled($kubectl, $ns)) {
            $this->laraKubeError('Stalwart is not installed. Run `larakube mail:init` first.');

            return 1;
        }

        $host = (string) $this->resolveMailHostReadOnly($env, $config);
        $domain = $this->testDomain($host);

        $to = (string) ($this->option('to') ?: text(
            label: 'Send the test email to',
            placeholder: 'you@example.com',
            required: true,
        ));

        $cachedSender = $this->readClusterSecretKey($kubectl, $ns, 'mail-sender', 'sender');
        $from = (string) ($this->option('from') ?: text(
            label: 'From — a Stalwart account',
            default: $cachedSender ?: ($domain !== '' ? 'noreply@'.$domain : ''),
            required: true,
        ));

        $pass = (string) ($this->option('password') ?: password(
            label: "Password for {$from}",
            required: true,
        ));

        $result = ['auth' => false, 'accepted' => false, 'raw' => ''];
        $this->withSpin("Sending a test email to {$to}...", function () use (&$result, $kubectl, $ns, $from, $pass, $to): void {
            $result = $this->sendViaSmtp($kubectl, $ns, $from, $pass, $to);
        });

        $this->laraKubeNewLine();

        if (! $result['auth']) {
            $this->laraKubeError("SMTP AUTH failed — Stalwart rejected {$from}'s password. Check the account + password (larakube mail:accounts / mail:password).");
            $this->printSmtpTrail($result['raw']);

            return 1;
        }

        if (! $result['accepted']) {
            $this->laraKubeError('Authenticated, but Stalwart did not accept the message. Server said:');
            $this->printSmtpTrail($result['raw']);

            return 1;
        }

        $this->laraKubeInfo("✅ Authenticated as {$from}, and Stalwart accepted the message for {$to}.");
        $this->newLine();

        $external = $domain !== '' && ! str_ends_with(strtolower($to), '@'.strtolower($domain));
        if ($external) {
            $relayOn = trim(Process::run(
                "{$kubectl} get secret mail-relay -n {$ns} --ignore-not-found -o name",
            )->output()) !== '';

            if ($relayOn) {
                $this->line("  <fg=gray>{$to} is external — it will relay out. Check that inbox (and spam) in a minute.</>");
            } else {
                $this->laraKubeWarn("{$to} is external and NO outbound relay is configured — the message will queue but NOT deliver (DigitalOcean blocks port 25). Set one up: larakube mail:relay.");
            }
        } else {
            $this->line("  <fg=gray>{$to} is a local account — it should already be in that mailbox. Check via an IMAP client.</>");
        }
        $this->newLine();

        return 0;
    }

    /**
     * Drive an SMTP submission conversation against Stalwart from inside the pod
     * (localhost:465, implicit TLS), so the operator's own network can't block it.
     *
     * @return array{auth: bool, accepted: bool, raw: string}
     */
    protected function sendViaSmtp(string $kubectl, string $ns, string $from, string $pass, string $to): array
    {
        $body = implode("\r\n", [
            "From: LaraKube Test <{$from}>",
            "To: <{$to}>",
            'Subject: LaraKube mail test',
            'Date: '.gmdate('r'),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=utf-8',
            '',
            'If you are reading this, your Stalwart mail server can authenticate and send. — LaraKube',
            '.',
        ]);

        $convo = implode("\r\n", [
            'EHLO larakube',
            'AUTH LOGIN',
            base64_encode($from),
            base64_encode($pass),
            "MAIL FROM:<{$from}>",
            "RCPT TO:<{$to}>",
            'DATA',
            $body,
            'QUIT',
        ])."\r\n";

        // Ship the whole conversation base64-encoded to sidestep shell quoting.
        // NO -crlf: the convo already uses \r\n, and -crlf would turn each into
        // \r\r\n — strict servers (e.g. SES) reject the doubled CR with a 501.
        $script = 'echo '.base64_encode($convo).' | base64 -d | openssl s_client -quiet -connect 127.0.0.1:465 2>/dev/null';
        $deployment = ClusterTool::MAIL->deploymentName($this->resolveMailInstance($kubectl));
        $raw = Process::timeout(40)->run(
            "{$kubectl} exec deploy/{$deployment} -n {$ns} -- sh -c ".escapeshellarg($script),
        )->output();

        $auth = str_contains($raw, '235');

        // A 250 AFTER the DATA "354" handshake is the queued/accepted confirmation.
        $accepted = false;
        if ($auth && ($dataPos = strpos($raw, '354')) !== false) {
            $accepted = (bool) preg_match('/(^|\n)\s*250/', substr($raw, $dataPos));
        }

        return ['auth' => $auth, 'accepted' => $accepted, 'raw' => $raw];
    }

    /** Print the last few SMTP status lines (NNN …) for transparency on failure. */
    protected function printSmtpTrail(string $raw): void
    {
        $lines = array_values(array_filter(
            array_map('trim', explode("\n", $raw)),
            fn ($l) => (bool) preg_match('/^\d{3}[ -]/', $l),
        ));
        foreach (array_slice($lines, -6) as $line) {
            $this->line('    <fg=gray>'.$line.'</>');
        }
    }

    /** Drop the leftmost label ("mail.example.com" → "example.com"). */
    protected function testDomain(string $host): string
    {
        $parts = explode('.', $host);

        return count($parts) > 2 ? implode('.', array_slice($parts, 1)) : $host;
    }
}
