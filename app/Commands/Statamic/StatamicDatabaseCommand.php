<?php

namespace App\Commands\Statamic;

use App\Enums\AppFramework;
use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ManagesStatamicDatabase;
use App\Traits\RunsKubectlSteps;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class StatamicDatabaseCommand extends Command
{
    use InteractsWithEnvironments, InteractsWithProjectConfig, LaraKubeOutput, ManagesStatamicDatabase, RunsKubectlSteps;

    protected $signature = 'statamic:database
                            {environment=local : The environment whose running site to set up}
                            {--email= : Super user email; unattended runs read the password from LARAKUBE_STATAMIC_PASSWORD}
                            {--no-user : Don\'t create a super user}';

    protected $description = 'Store a Statamic site\'s content and users in its database, and create its super user';

    public function handle(): int
    {
        $this->renderHeader();

        $config = $this->getProjectConfig(getcwd());
        if ($config === null || $config->framework !== AppFramework::STATAMIC) {
            $this->laraKubeError('Run this from a Statamic project created with the LaraKube CLI.');

            return 1;
        }

        $environment = (string) $this->argument('environment');

        $failed = $this->useDatabaseUsers((string) getcwd());
        if ($failed !== [] && ! $this->usersAlreadyInDatabase()) {
            $this->laraKubeError('Could not switch users to the database; these files differ from Statamic\'s defaults: '.implode(', ', $failed).'.');
            $this->laraKubeLine('  <fg=gray>Follow statamic.dev/tips/storing-users-in-a-database for them, then re-run.</>');

            return 1;
        }

        $superUser = $this->option('no-user') ? null : $this->askForSuperUser($environment);

        if (! $this->setUpStatamicDatabase($config, $environment, $superUser)) {
            return 1;
        }

        $this->laraKubeInfo("✅ Content and users are in {$environment}'s database.");
        if ($environment !== 'local') {
            $this->laraKubeLine('  <fg=gray>Commit the files this changed (composer.json, config/, database/migrations/) so every environment matches.</>');
        }

        return 0;
    }

    private function usersAlreadyInDatabase(): bool
    {
        return str_contains((string) @file_get_contents(getcwd().'/config/statamic/users.php'), "'repository' => 'eloquent',");
    }

    /** @return array{email: string, password: string}|null */
    private function askForSuperUser(string $environment): ?array
    {
        $email = trim((string) $this->option('email'));

        if ($this->option('no-interaction')) {
            $password = (string) getenv('LARAKUBE_STATAMIC_PASSWORD');
            if ($email === '' || $password === '') {
                $this->laraKubeWarn('Skipping the super user: pass --email and set LARAKUBE_STATAMIC_PASSWORD.');

                return null;
            }

            if (($reason = StatamicNewCommand::weakPasswordReason($password, $email)) !== null) {
                $this->laraKubeError("LARAKUBE_STATAMIC_PASSWORD: {$reason}");

                return null;
            }

            return ['email' => $email, 'password' => $password];
        }

        if ($email === '') {
            $email = trim(text(
                label: "Super user email for {$environment}",
                required: true,
                validate: fn (string $value) => filter_var($value, FILTER_VALIDATE_EMAIL) ? null : 'Enter a valid email address.',
            ));
        }

        return ['email' => $email, 'password' => password(
            label: 'Super user password',
            required: true,
            validate: fn (string $value) => StatamicNewCommand::weakPasswordReason($value, $email),
        )];
    }
}
