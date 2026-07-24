<?php

namespace App\Commands\Sso;

use LaravelZero\Framework\Commands\Command;

class SsoUnwireCommand extends Command
{
    protected $signature = 'sso:unwire
        {environment=local : Environment whose tool SSO to unwire}
        {--tool= : The tool to unwire from Zitadel SSO}
        {--context= : Target a specific kube-context}
        {--project= : Zitadel project name (default: LaraKube Shared Tools)}';

    protected $description = 'Unwire a tool from Zitadel SSO and deregister its OIDC application';

    public function handle(): int
    {
        $params = [
            'environment' => $this->argument('environment'),
            '--remove' => true,
        ];

        if ($this->option('tool')) {
            $params['--tool'] = $this->option('tool');
        }

        if ($this->option('context')) {
            $params['--context'] = $this->option('context');
        }

        if ($this->option('project')) {
            $params['--project'] = $this->option('project');
        }

        return $this->call('sso:wire', $params);
    }
}
