<?php

namespace App\Commands\Mail;

use App\Data\ConfigData;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithMail;
use App\Traits\InteractsWithStalwartApi;
use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

/**
 * Inspect and unclog Stalwart's outbound queue. A backlog here is the fastest
 * explanation for "I sent it but it never arrived": the message was accepted
 * and queued, but delivery keeps failing (classically, mail baked to a route
 * that can't connect — e.g. direct MX on a host where port 25 is blocked and
 * no relay was configured yet). Stalwart fixes a message's route at queue time,
 * so re-pointing outbound at a working relay later does NOT rescue already-
 * queued mail — you retry (if the route is now reachable) or cancel and resend.
 */
class MailQueueCommand extends Command
{
    use InteractsWithClusterContext, InteractsWithMail, InteractsWithStalwartApi, LaraKubeOutput;

    protected $signature = 'mail:queue
        {environment=local : Environment whose mail server to target}
        {--retry     : Force an immediate delivery retry of every queued message}
        {--cancel    : Drop every queued message from the queue (undeliverable clog)}
        {--context=  : Target a specific kube-context}';

    protected $description = "Inspect and unclog Stalwart's outbound mail queue (list, retry, or cancel stuck mail)";

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

        $messages = $this->stalwartQueuedMessages($kubectl, $ns);
        if ($messages === null) {
            $this->laraKubeError('Could not connect to the Stalwart API.');

            return 1;
        }

        if ($messages === []) {
            $this->laraKubeInfo('✅ Outbound queue is empty — nothing waiting to send.');

            return 0;
        }

        $ids = array_values(array_map(fn (array $m): string => (string) $m['id'], $messages));

        if ($this->option('cancel')) {
            return $this->cancel($kubectl, $ns, $ids);
        }

        if ($this->option('retry')) {
            $n = $this->stalwartRetryQueued($kubectl, $ns, $ids);
            $this->laraKubeInfo("Rescheduled {$n} message(s) for immediate delivery.");
            $this->line('  <fg=gray>Messages baked to an unreachable route (e.g. direct MX where a relay is required) will just fail again — drop those with</> <fg=blue>--cancel</><fg=gray>.</>');
            $this->newLine();

            return 0;
        }

        $this->renderTable($messages);
        $this->newLine();
        $this->line("  <fg=gray>Force delivery now:</>  <fg=blue>larakube mail:queue --retry --env={$env}</>");
        $this->line("  <fg=gray>Drop undeliverable:</>  <fg=blue>larakube mail:queue --cancel --env={$env}</>");
        $this->newLine();

        return 0;
    }

    /**
     * @param  array<int, string>  $ids
     */
    protected function cancel(string $kubectl, string $ns, array $ids): int
    {
        $count = count($ids);

        if (! $this->option('no-interaction') && ! confirm(
            label: "Drop {$count} message(s) from the outbound queue? They are permanently discarded (not delivered).",
            default: false,
        )) {
            $this->laraKubeInfo('Left the queue untouched.');

            return 0;
        }

        $n = $this->stalwartCancelQueued($kubectl, $ns, $ids);
        $this->laraKubeInfo("Dropped {$n} message(s) from the queue.");
        $this->newLine();

        return 0;
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     */
    protected function renderTable(array $messages): void
    {
        $rows = [];
        foreach ($messages as $m) {
            $from = $m['returnPath'] ?? '-';
            $age = isset($m['createdAt']) ? $this->humanAge((string) $m['createdAt']) : '-';

            foreach (($m['recipients'] ?? []) as $addr => $r) {
                $status = is_array($r) ? ($r['status'] ?? []) : [];
                $err = $status['errorMessage'] ?? ($status['@type'] ?? '');
                $rows[] = [
                    (string) $addr,
                    (string) $from,
                    $age,
                    (string) ($r['retryCount'] ?? 0),
                    mb_strimwidth((string) $err, 0, 44, '…'),
                ];
            }
        }

        $this->newLine();
        table(['To', 'From', 'Age', 'Tries', 'Last error'], $rows);
    }

    protected function humanAge(string $iso): string
    {
        $t = strtotime($iso);
        if ($t === false) {
            return '-';
        }

        $s = max(0, time() - $t);

        return match (true) {
            $s < 3600 => intdiv($s, 60).'m',
            $s < 86400 => intdiv($s, 3600).'h',
            default => intdiv($s, 86400).'d',
        };
    }
}
