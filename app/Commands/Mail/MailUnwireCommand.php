<?php

namespace App\Commands\Mail;

use LaravelZero\Framework\Commands\Command;

class MailUnwireCommand extends Command
{
    protected $signature = 'mail:unwire
        {environment=local : Environment whose mail settings to unwire}
        {--tool= : The tool to unwire from Stalwart}
        {--all   : Unwire every installed SMTP-capable tool}
        {--context= : Target a specific kube-context}';

    protected $description = 'Unwire SMTP mail settings from a tool and restart its deployment';

    public function handle(): int
    {
        $params = [
            'environment' => $this->argument('environment'),
            '--remove' => true,
        ];

        if ($this->option('tool')) {
            $params['--tool'] = $this->option('tool');
        }

        if ($this->option('all')) {
            $params['--all'] = true;
        }

        if ($this->option('context')) {
            $params['--context'] = $this->option('context');
        }

        return $this->call('mail:wire', $params);
    }
}
