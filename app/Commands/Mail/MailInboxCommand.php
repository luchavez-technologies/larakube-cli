<?php

namespace App\Commands\Mail;

use App\Data\ConfigData;
use App\Exceptions\MissingFlagException;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithMail;
use App\Traits\InteractsWithStalwartApi;
use App\Traits\LaraKubeOutput;
use App\Traits\RequiresFlagsWhenNonInteractive;
use Illuminate\Support\Carbon;

use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;
use stdClass;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;

class MailInboxCommand extends Command
{
    use InteractsWithClusterContext, InteractsWithMail, InteractsWithStalwartApi, LaraKubeOutput, RequiresFlagsWhenNonInteractive;

    protected $signature = 'mail:inbox
        {environment=local : Environment whose mail server to target}
        {--email= : Email address of the account}
        {--limit=10  : Number of emails to display}
        {--context=  : Target a specific kube-context}';

    protected $description = 'View recent emails for a Stalwart mail account';

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

        $accounts = $this->stalwartAccounts($kubectl, $ns);
        if ($accounts === null) {
            $this->laraKubeError('Could not connect to the Stalwart API.');

            return 1;
        }

        if ($accounts === []) {
            $this->laraKubeInfo('No accounts found.');

            return 0;
        }

        $target = $this->resolveTarget($accounts);

        if ($target === null) {
            return 1;
        }

        $limit = (int) $this->option('limit');
        if ($limit < 1) {
            $limit = 10;
        }

        $emails = [];
        $this->withSpin("Fetching emails for {$target['email']}...", function () use ($kubectl, $ns, $target, $limit, &$emails) {
            $using = ['urn:ietf:params:jmap:core', 'urn:stalwart:jmap', 'urn:ietf:params:jmap:mail'];

            $query = $this->stalwartJmap($kubectl, $ns, [
                [
                    'Email/query',
                    [
                        'accountId' => $target['id'],
                        'filter' => new stdClass,
                        'sort' => [['property' => 'receivedAt', 'isAscending' => false]],
                        'limit' => $limit,
                    ],
                    'c0',
                ],
            ], $using);

            if ($query === null) {
                return;
            }

            $ids = $query[0][1]['ids'] ?? [];
            if (empty($ids)) {
                return;
            }

            $get = $this->stalwartJmap($kubectl, $ns, [
                [
                    'Email/get',
                    [
                        'accountId' => $target['id'],
                        'ids' => $ids,
                        'properties' => ['subject', 'from', 'receivedAt', 'preview'],
                    ],
                    'c1',
                ],
            ], $using);

            if ($get !== null) {
                $emails = $get[0][1]['list'] ?? [];
            }
        });

        if (empty($emails)) {
            $this->laraKubeNewLine();
            $this->laraKubeInfo("No emails found in {$target['email']} inbox.");
            $this->newLine();

            return 0;
        }

        $this->laraKubeNewLine();
        $this->laraKubeInfo("Recent emails for {$target['email']}:");
        $this->newLine();

        $table = new Table($this->output);
        $table->setHeaders(['Date', 'From', 'Subject', 'Preview']);
        $table->setStyle('box');

        foreach ($emails as $index => $email) {
            $date = Carbon::parse($email['receivedAt'] ?? now())->timezone(date_default_timezone_get())->format('Y-m-d H:i');

            $fromName = $email['from'][0]['name'] ?? '';
            $fromEmail = $email['from'][0]['email'] ?? 'Unknown';
            $from = $fromName ? "{$fromName} <{$fromEmail}>" : $fromEmail;

            $subject = wordwrap($email['subject'] ?? '(No Subject)', 40, "\n", true);
            $preview = wordwrap(trim($email['preview'] ?? ''), 50, "\n", true);

            $table->addRow([$date, $from, $subject, $preview]);

            if ($index < count($emails) - 1) {
                $table->addRow(new TableSeparator);
            }
        }

        $table->render();
        $this->newLine();

        return 0;
    }

    protected function resolveTarget(array $accounts): ?array
    {
        $email = (string) ($this->option('email') ?? '');

        if ($email !== '') {
            foreach ($accounts as $a) {
                $addr = $a['emailAddress'] ?? ($a['name'].'@?');
                if ($addr === $email) {
                    return ['id' => $a['id'], 'email' => $addr, 'name' => $a['description'] ?? $a['name']];
                }
            }

            $this->laraKubeError("Account '{$email}' not found.");

            return null;
        }

        $options = [];
        foreach ($accounts as $a) {
            $addr = $a['emailAddress'] ?? ($a['name'].'@?');
            $options[$a['id']] = $addr.' — '.($a['description'] ?? $a['name']);
        }

        // No --email and no way to ask: fail with the flag name rather than
        // hanging on a prompt that will never be answered (CI, MCP, larakube proxy).
        if ($this->cannotPrompt()) {
            throw new MissingFlagException('email', 'which mailbox to read', 'larakube mail:inbox production --email=…');
        }
        $choice = select(
            label: 'Which account inbox would you like to view?',
            options: $options,
            scroll: count($options),
        );

        foreach ($accounts as $a) {
            if ($a['id'] === $choice) {
                return ['id' => $a['id'], 'email' => $a['emailAddress'] ?? ($a['name'].'@?'), 'name' => $a['description'] ?? $a['name']];
            }
        }

        return null;
    }
}
