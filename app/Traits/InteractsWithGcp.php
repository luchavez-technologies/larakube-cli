<?php

namespace App\Traits;

use App\Enums\CliTool;
use App\Facades\State;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

trait InteractsWithGcp
{
    use InteractsWithGlobalConfig, StreamsProcessOutput;

    /**
     * Prompt for + persist GCP credentials and project ID, ensuring active authentication.
     */
    protected function ensureGcpCredentials(): bool
    {
        if ($flagAccount = $this->flag('gcp-account')) {
            State::setTransientGcpAccount($flagAccount);
        }

        if ($flagProject = $this->flag('gcp-project')) {
            State::setTransientGcpProject($flagProject);
        }

        if ($flagCreds = $this->flag('gcp-credentials')) {
            $path = str_replace('~', home_path(), trim($flagCreds));
            if (! file_exists($path)) {
                $this->laraKubeError("GCP credentials file not found at: {$path}");

                return false;
            }
            State::setTransientGcpCredentials($path);
            $this->registerSecret(State::transientGcpCredentials());
        }

        // Offer gcloud install if missing and running interactively
        if (! CliTool::GCLOUD->isInstalled() && ! $this->flag('no-interaction') && $this->output !== null) {
            if (confirm('Google Cloud CLI (gcloud) is not installed. Would you like to install it now via larakube setup?', default: false)) {
                $this->call('setup', ['--tools' => 'gcloud']);
            }
        }

        $gcloudBin = CliTool::GCLOUD->resolveBinary() ?? 'gcloud';

        // Switch to explicit account if specified
        if ($account = $this->getGcpAccount()) {
            if (CliTool::GCLOUD->isInstalled()) {
                Process::run("{$gcloudBin} config set account ".escapeshellarg($account).' 2>/dev/null');
            }
        }

        // Step 1: Handle non-interactive mode cleanly
        $credentials = $this->getGcpCredentials();
        $gcloudAuthed = CliTool::GCLOUD->isInstalled() && Process::run("{$gcloudBin} auth print-access-token 2>/dev/null")->successful();

        if ($this->flag('no-interaction')) {
            $projectId = $this->getGcpProjectId();
            if (! $projectId) {
                $this->laraKubeError('No Google Cloud Project ID found. Pass --gcp-project= or set GOOGLE_PROJECT when running non-interactively.');

                return false;
            }

            if (! $credentials && ! $gcloudAuthed) {
                $this->laraKubeError('No GCP credentials detected. Pass --gcp-credentials= or authenticate via `gcloud auth application-default login`.');

                return false;
            }

            return true;
        }

        // Step 2: Interactive authentication & Multi-Account Selection
        if (CliTool::GCLOUD->isInstalled()) {
            $accounts = $this->listGcpAccounts($gcloudBin);

            if (empty($accounts) && ! $gcloudAuthed) {
                $this->newLine();
                $this->laraKubeWarn('Google Cloud CLI (gcloud) is not logged in.');

                if (! app()->runningUnitTests() && ! Process::isRecording() && confirm('Open browser to log in via gcloud now?', default: true)) {
                    $loginCode = $this->runInteractive("{$gcloudBin} auth login --update-adc");
                    if ($loginCode === 0) {
                        $this->line('  <fg=green>✓</> <fg=gray>Successfully authenticated with Google Cloud.</>');
                        $gcloudAuthed = Process::run("{$gcloudBin} auth print-access-token 2>/dev/null")->successful();
                        $credentials = $this->getGcpCredentials();
                        $accounts = $this->listGcpAccounts($gcloudBin);
                    }
                }
            }

            // Multi-account picker if multiple accounts are logged in and no specific flag was given
            if (count($accounts) > 1 && ! $this->flag('gcp-account') && ! State::transientGcpAccount()) {
                $options = [];
                $activeAccount = null;
                foreach ($accounts as $email => $isActive) {
                    if ($isActive) {
                        $activeAccount = $email;
                    }
                    $options[$email] = "{$email}".($isActive ? ' [active]' : '');
                }
                $options['__add__'] = '+ Log in to another Google account';

                $default = $activeAccount ?? array_key_first($options);

                $chosen = select(
                    label: 'Which Google Cloud account would you like to use?',
                    options: $options,
                    default: $default,
                );

                if ($chosen === '__add__') {
                    if (! app()->runningUnitTests() && ! Process::isRecording()) {
                        $loginCode = $this->runInteractive("{$gcloudBin} auth login --update-adc");
                    }
                    $accounts = $this->listGcpAccounts($gcloudBin);
                    $chosen = array_key_first(array_filter($accounts, fn ($active) => $active)) ?? array_key_first($accounts) ?? null;
                }

                if ($chosen && $chosen !== '__add__') {
                    Process::run("{$gcloudBin} config set account ".escapeshellarg($chosen));
                    State::setTransientGcpAccount($chosen);
                    $this->setGcpAccount($chosen);
                    $this->line("  <fg=green>✓</> <fg=gray>Switched to Google Cloud account</> <fg=cyan>{$chosen}</>");
                }
            } elseif (count($accounts) === 1 && ! State::transientGcpAccount() && ! $this->flag('gcp-account')) {
                $singleAccount = array_key_first($accounts);
                State::setTransientGcpAccount($singleAccount);
                $this->setGcpAccount($singleAccount);
                Process::run("{$gcloudBin} config set account ".escapeshellarg($singleAccount).' 2>/dev/null');
            }
        }

        if ($gcloudAuthed) {
            $activeAcc = $this->getGcpAccount();
            $accLabel = $activeAcc ? " (<fg=cyan>{$activeAcc}</>)" : '';
            $this->line("  <fg=green>✓</> <fg=gray>Detected active authentication via local</> <fg=cyan>gcloud</> <fg=gray>CLI{$accLabel}.</>");
        }

        if (! $gcloudAuthed && ! $credentials) {
            $this->line('  <fg=gray>Options: (1) Run `gcloud auth login --update-adc` in another terminal, or</>');
            $this->line('  <fg=gray>         (2) Provide a path to a downloaded Service Account JSON key.</>');
            $credsPath = text(
                label: 'Path to Service Account JSON key (or leave blank if logging in via gcloud)',
                required: false,
                hint: 'Leave blank if you logged in via gcloud auth login.',
            );

            if ($credsPath !== '') {
                $resolvedPath = str_replace('~', home_path(), trim($credsPath));
                if (! file_exists($resolvedPath)) {
                    $this->laraKubeError("GCP credentials file not found at: {$resolvedPath}");

                    return false;
                }
                $this->setGcpCredentials($resolvedPath);
                $this->laraKubeInfo('Saved GCP credentials path to your global LaraKube config.');
            } else {
                $this->laraKubeError('Google Cloud authentication is required to continue.');

                return false;
            }
        }

        // Step 3: Resolve Project ID interactively
        if ($this->flag('gcp-project') || State::transientGcpProject()) {
            return true;
        }

        $projectId = $this->resolveGcpProjectId($gcloudBin);
        if (! $projectId) {
            $this->laraKubeError('A Google Cloud Project ID is required to continue.');

            return false;
        }

        $this->setGcpProjectId($projectId);
        $this->laraKubeInfo("Using Google Cloud project: {$projectId}");

        return true;
    }

    /**
     * Discover projects via gcloud or prompt to enter/create one.
     */
    protected function resolveGcpProjectId(string $gcloudBin): ?string
    {
        $defaultProjectId = $this->getGlobalConfig()->getGcpProjectId();
        if (! $defaultProjectId && CliTool::GCLOUD->isInstalled()) {
            $detected = trim(Process::run("{$gcloudBin} config get-value project 2>/dev/null")->output());
            if ($detected !== '' && $detected !== '(unset)' && ! str_contains($detected, 'ERROR')) {
                $defaultProjectId = $detected;
            }
        }

        if (! CliTool::GCLOUD->isInstalled()) {
            $this->laraKubeWarn('Google Cloud CLI (gcloud) is not installed; unable to auto-discover projects.');

            return text(
                label: 'Google Cloud Project ID',
                default: $defaultProjectId ?? '',
                placeholder: 'my-project-12345',
                required: true,
                hint: 'Find this in your Google Cloud Console dashboard.',
            );
        }

        $projects = [];
        $account = $this->getGcpAccount();
        $accountArg = $account ? ' --account='.escapeshellarg($account) : '';
        $result = Process::run("{$gcloudBin} projects list --format=\"json(projectId,name)\"{$accountArg}");
        if ($result->successful() && ! empty(trim($result->output()))) {
            $decoded = json_decode($result->output(), true);
            if (is_array($decoded)) {
                $projects = $decoded;
            }
        } elseif (! $result->successful()) {
            $err = trim($result->errorOutput() ?: $result->output());
            if ($err !== '' && ! str_contains($err, 'WARNING')) {
                $this->laraKubeWarn("Unable to list Google Cloud projects: {$err}");
            }
        }

        $options = [];
        foreach ($projects as $proj) {
            if (empty($proj['projectId'])) {
                continue;
            }
            $id = $proj['projectId'];
            $name = ! empty($proj['name']) ? $proj['name'] : $id;
            $options[$id] = $name !== $id ? "{$name} ({$id})" : $id;
        }

        $options['__create__'] = '+ Create a new Google Cloud project';
        $options['__custom__'] = '✎ Enter Project ID manually';

        $default = null;
        if ($defaultProjectId && isset($options[$defaultProjectId])) {
            $default = $defaultProjectId;
        } elseif (! empty($projects)) {
            $default = array_key_first($options);
        } else {
            $default = '__create__';
        }

        $account = $this->getGcpAccount();
        $accountSuffix = $account ? " (for {$account})" : '';
        $chosen = select(
            label: "Google Cloud Project{$accountSuffix}",
            options: $options,
            default: $default,
        );

        if ($chosen === '__create__') {
            return $this->createGcpProjectFlow($gcloudBin);
        }

        if ($chosen === '__custom__') {
            return text(
                label: 'Google Cloud Project ID',
                default: $defaultProjectId ?? '',
                placeholder: 'my-project-12345',
                required: true,
                hint: 'Find this in your Google Cloud Console dashboard.',
            );
        }

        return $chosen;
    }

    /**
     * Guide the operator through creating a new GCP project with billing and APIs.
     */
    protected function createGcpProjectFlow(string $gcloudBin): ?string
    {
        $projectName = text(
            label: 'Google Cloud Project Name',
            placeholder: 'LaraKube Fleet',
            required: true,
            hint: 'A human-readable name for your new Google Cloud project.',
        );

        $slug = Str::slug($projectName);
        if (empty($slug) || ! ctype_alpha($slug[0])) {
            $slug = 'project-'.$slug;
        }
        $slug = substr($slug, 0, 24);
        $suggestedId = rtrim($slug, '-').'-'.substr(bin2hex(random_bytes(4)), 0, 5);

        $projectId = text(
            label: 'Project ID (globally unique across Google Cloud)',
            default: $suggestedId,
            required: true,
            validate: fn (string $val) => preg_match('/^[a-z][a-z0-9-]{4,28}[a-z0-9]$/', $val)
                ? null
                : 'Project ID must be 6-30 characters, start with a lowercase letter, contain only lowercase letters, digits, and hyphens, and not end with a hyphen.',
            hint: 'Must be globally unique across all Google Cloud customers.',
        );

        $account = $this->getGcpAccount();
        $accountArg = $account ? ' --account='.escapeshellarg($account) : '';
        $this->line("Creating Google Cloud project '<fg=cyan>{$projectId}</>'...");
        $createCmd = "{$gcloudBin} projects create ".escapeshellarg($projectId).' --name='.escapeshellarg($projectName).$accountArg;
        $result = Process::run($createCmd);

        if (! $result->successful()) {
            $err = trim($result->errorOutput() ?: $result->output());
            $this->laraKubeError("Failed to create Google Cloud project: {$err}");

            return null;
        }

        $this->line("  <fg=green>✓</> <fg=gray>Project</> <fg=cyan>{$projectId}</> <fg=gray>created successfully.</>");

        // Set as active project in local gcloud config
        Process::run("{$gcloudBin} config set project ".escapeshellarg($projectId));

        // Offer to link billing
        $this->linkBillingAccountIfAvailable($gcloudBin, $projectId);

        // Enable baseline APIs
        $this->enableGcpBaselineApis($gcloudBin, $projectId);

        return $projectId;
    }

    /**
     * Query and link open billing accounts to the new project.
     */
    protected function linkBillingAccountIfAvailable(string $gcloudBin, string $projectId): void
    {
        $account = $this->getGcpAccount();
        $accountArg = $account ? ' --account='.escapeshellarg($account) : '';
        $billingCmd = "{$gcloudBin} billing accounts list --format=\"json(name,displayName,open)\" --filter=\"open=true\"{$accountArg} 2>/dev/null";
        $billingResult = Process::run($billingCmd);

        if (! $billingResult->successful() || empty(trim($billingResult->output()))) {
            $this->laraKubeWarn('No open Google Cloud billing accounts found or unable to list billing accounts.');
            $this->line('  <fg=gray>You can link billing later in the Google Cloud Console before provisioning resources.</>');

            return;
        }

        $accounts = json_decode($billingResult->output(), true);
        if (! is_array($accounts) || empty($accounts)) {
            $this->laraKubeWarn('No open Google Cloud billing accounts found.');
            $this->line('  <fg=gray>You can link billing later in the Google Cloud Console before provisioning resources.</>');

            return;
        }

        if (count($accounts) === 1) {
            $account = $accounts[0];
            $accountId = str_replace('billingAccounts/', '', $account['name'] ?? '');
            $displayName = $account['displayName'] ?? $accountId;

            if (confirm("Link billing account '{$displayName} ({$accountId})' to project '{$projectId}'?", default: true)) {
                $this->linkBillingAccount($gcloudBin, $projectId, $accountId);
            }

            return;
        }

        $options = [];
        foreach ($accounts as $acc) {
            $accId = str_replace('billingAccounts/', '', $acc['name'] ?? '');
            if ($accId === '') {
                continue;
            }
            $dispName = $acc['displayName'] ?? $accId;
            $options[$accId] = "{$dispName} ({$accId})";
        }
        $options['__skip__'] = 'Skip (link later in Google Cloud Console)';

        $chosen = select(
            label: "Link billing account to '{$projectId}'",
            options: $options,
            default: array_key_first($options),
        );

        if ($chosen !== '__skip__') {
            $this->linkBillingAccount($gcloudBin, $projectId, $chosen);
        }
    }

    /**
     * Link a specific billing account to a GCP project.
     */
    protected function linkBillingAccount(string $gcloudBin, string $projectId, string $billingAccountId): bool
    {
        $account = $this->getGcpAccount();
        $accountArg = $account ? ' --account='.escapeshellarg($account) : '';
        $linkCmd = "{$gcloudBin} billing projects link ".escapeshellarg($projectId).' --billing-account='.escapeshellarg($billingAccountId).$accountArg;
        $linkResult = Process::run($linkCmd);

        if ($linkResult->successful()) {
            $this->line('  <fg=green>✓</> <fg=gray>Billing account linked successfully.</>');

            return true;
        }

        $err = trim($linkResult->errorOutput() ?: $linkResult->output());
        $this->laraKubeWarn("Could not link billing account: {$err}");

        return false;
    }

    /**
     * Enable Compute Engine, GKE, and Cloud Resource Manager APIs.
     */
    protected function enableGcpBaselineApis(string $gcloudBin, string $projectId): void
    {
        $account = $this->getGcpAccount();
        $accountArg = $account ? ' --account='.escapeshellarg($account) : '';
        $this->line('Enabling required Google Cloud APIs (Compute Engine, GKE, & Resource Manager)...');
        $servicesCmd = "{$gcloudBin} services enable compute.googleapis.com container.googleapis.com cloudresourcemanager.googleapis.com --project=".escapeshellarg($projectId).$accountArg;
        $servicesResult = Process::run($servicesCmd);

        if ($servicesResult->successful()) {
            $this->line('  <fg=green>✓</> <fg=gray>Google Cloud APIs enabled.</>');
        } else {
            $err = trim($servicesResult->errorOutput() ?: $servicesResult->output());
            $this->laraKubeWarn("Could not enable APIs automatically: {$err}");
            $this->line('  <fg=gray>You can enable Compute Engine in the Google Cloud Console if needed.</>');
        }
    }

    /**
     * Discover authenticated Google Cloud accounts on this machine.
     *
     * @return array<string, bool> Map of email => is_active
     */
    protected function listGcpAccounts(string $gcloudBin): array
    {
        if (! CliTool::GCLOUD->isInstalled()) {
            return [];
        }

        $result = Process::run("{$gcloudBin} auth list --format=\"json(account,status)\" 2>/dev/null");
        if (! $result->successful() || empty(trim($result->output()))) {
            return [];
        }

        $data = json_decode($result->output(), true);
        if (! is_array($data)) {
            return [];
        }

        $accounts = [];
        foreach ($data as $item) {
            if (! empty($item['account'])) {
                $accounts[$item['account']] = ($item['status'] ?? '') === 'ACTIVE';
            }
        }

        return $accounts;
    }
}
