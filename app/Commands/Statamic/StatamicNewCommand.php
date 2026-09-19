<?php

namespace App\Commands\Statamic;

use App\Data\ConfigData;
use App\Enums\AppFramework;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\LaravelFeature;
use App\Enums\PackageManager;
use App\Enums\PhpVersion;
use App\Enums\SearchDriver;
use App\Enums\ServerVariation;
use App\Enums\StorageDriver;
use App\Traits\CheckPrerequisites;
use App\Traits\GathersInfrastructureConfig;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\HasConsoleInteraction;
use App\Traits\InteractsWithArchitecturalEngine;
use App\Traits\InteractsWithDocker;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\SyncsClusterSecrets;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use InvalidArgumentException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;
use Random\RandomException;

class StatamicNewCommand extends Command
{
    use CheckPrerequisites, GathersInfrastructureConfig, GeneratesProjectInfrastructure, HasConsoleInteraction, InteractsWithArchitecturalEngine, InteractsWithDocker, InteractsWithPlex, InteractsWithProjectConfig, LaraKubeOutput, SyncsClusterSecrets;

    /** The Statamic CLI release line the scaffold installs. */
    private const string STATAMIC_CLI = 'statamic/cli:^3.6';

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'statamic:new
                            {name? : The name of the Statamic site}
                            {--fast : Skip wizard and use ideal defaults}
                            {--no-plex : Skip Plex Commons auto-provisioning and use self-hosted databases}
                            {--starter-kit= : A Statamic starter kit to install, as vendor/kit (e.g. jasonbaciulis/bedrock)}
                            {--pro : Enable Statamic Pro}
                            {--license= : License key for a paid starter kit (required when unattended)}
                            {--with-config : Keep the kit\'s starter-kit.yaml, for developing the kit itself}
                            {--without-dependencies : Install the kit without its Composer dependencies}
                            {--email= : Super user email; unattended runs read the password from LARAKUBE_STATAMIC_PASSWORD}
                            {--no-user : Don\'t create a super user}';

    /**
     * The console command description.
     */
    protected $description = 'Scaffold a new Statamic CMS project with Kubernetes infrastructure';

    private ?string $starterKit = null;

    /** @var array{email: string, password: string}|null */
    private ?array $superUser = null;

    /**
     * Execute the console command.
     *
     * @throws RandomException
     */
    public function handle(): int
    {
        $this->renderHeader();
        $this->plexContext = null;

        $projectPath = getcwd();

        if (! $this->checkPrerequisites(false)) {
            return 1;
        }

        $inputName = $this->argument('name') ?? text(
            label: 'What is the name of your Statamic site?',
            placeholder: 'my-statamic-site',
            required: true,
            validate: fn (string $value) => match (true) {
                strtolower($value) === 'console' => 'The name "console" is reserved for the LaraKube Console.',
                default => null,
            },
        );

        $appName = Str::slug($inputName);
        $projectDir = "$projectPath/$appName";

        $this->starterKit = $this->resolveStarterKit();

        // 1. PHP Version. A starter kit may require newer; that is read back from
        // its composer.json after install.
        $version = $this->option('fast')
            ? PhpVersion::PHP_8_5->value
            : select(
                label: 'Which PHP version would you like to use?',
                options: collect(PhpVersion::cases())
                    ->filter(fn ($v) => (float) $v->value >= 8.2)
                    ->mapWithKeys(fn ($v) => [$v->value => $v->getLabel()])
                    ->all(),
                default: PhpVersion::PHP_8_5->value,
            );
        $phpVersion = PhpVersion::from($version);

        // Build the config here, not after the prompts: the feature multiselect
        // below needs it, and the driver prompts that follow READ the features.
        $config = new ConfigData;
        $config->setIsScaffolding(true);
        $config->setName($appName);
        $config->setPath($projectDir);
        $config->setEnvironments(['local']);
        $config->framework = AppFramework::STATAMIC;
        // Statamic inherited this from the Laravel blueprint path until it got
        // its own command; without it the manifest views deref null on
        // getServerVariation()->value and orchestration dies.
        $config->serverVariation = ServerVariation::FPM_NGINX;
        $config->phpVersion = $phpVersion;
        // Statamic ships an npm-based front end and this wizard does not ask;
        // recording it keeps the blueprint explicit instead of leaning on
        // getPackageManager()'s fallback at every call site.
        $config->setPackageManager(PackageManager::NPM);

        // 2. Laravel features. Asked BEFORE the drivers, exactly as
        // gatherConfig() does, because the driver steps below depend on them:
        // Horizon forces Redis (and skips the cache question), and AI flips the
        // database default to PostgreSQL for pgvector. Statamic IS a Laravel
        // app, so these applied until b7d7a10 moved it off the Blueprint path.
        $featureOptions = LaravelFeature::getSelectOptions($config);

        if (! empty($featureOptions)) {
            $features = $this->option('fast')
                ? [LaravelFeature::TASK_SCHEDULING->value, LaravelFeature::HORIZON->value]
                : multiselect(
                    label: 'Select Laravel features:',
                    options: $featureOptions,
                    // Whatever the config already carries — nothing, for a fresh
                    // scaffold. Same as gatherConfig(): the wizard must not
                    // pre-tick infrastructure the user never asked for.
                    default: array_map(fn (LaravelFeature $f) => $f->value, $config->getFeatures()),
                    scroll: count($featureOptions),
                    validate: function (array $values) {
                        // Both would work the same queue twice.
                        if (in_array(LaravelFeature::HORIZON->value, $values, true)
                            && in_array(LaravelFeature::QUEUES->value, $values, true)) {
                            return 'You cannot select both Horizon and Queues. Please choose one.';
                        }

                        return null;
                    },
                );

            $config->setFeatures(array_map(
                fn (string $feature) => LaravelFeature::from($feature),
                array_values(array_filter($features)),
            ));
        }

        // 3. DatabaseDriver — MySQL, MariaDB, PostgreSQL only (plan §2a)
        $allowedDbs = collect(DatabaseDriver::cases())
            ->filter(fn ($d) => in_array($d, [
                DatabaseDriver::MYSQL,
                DatabaseDriver::MARIADB,
                DatabaseDriver::POSTGRESQL,
            ], true))
            ->mapWithKeys(fn ($d) => [$d->value => $d->getLabel()])
            ->all();

        $defaultDb = DatabaseDriver::MYSQL->value;

        if ($config->hasFeature(LaravelFeature::AI)) {
            $defaultDb = DatabaseDriver::POSTGRESQL->value;
            $this->laraKubeInfo('AI SDK detected: PostgreSQL with <fg=cyan;options=bold>pgvector</> is recommended for vector storage.');
        }

        $dbValue = $this->option('fast')
            ? $defaultDb
            : select(
                label: 'Which database engine would you like to use?',
                options: $allowedDbs,
                default: $defaultDb,
            );
        $database = DatabaseDriver::from($dbValue);

        // 4. CacheDriver — all three available for Statamic (plan §2b)
        $allowedCaches = collect(CacheDriver::cases())
            ->mapWithKeys(fn ($c) => [$c->value => $c->getLabel()])
            ->all();

        if ($config->hasFeature(LaravelFeature::HORIZON)) {
            // Not a prompt: Horizon IS Redis queues, so offering Memcached here
            // would let the wizard produce a Horizon install with nothing to run on.
            $this->laraKubeInfo('Horizon detected: Auto-selecting Redis for caching and queues.');
            $cacheDriver = CacheDriver::REDIS;
        } else {
            $cacheValue = $this->option('fast')
                ? CacheDriver::REDIS->value
                : select(
                    label: 'Which cache driver would you like to use?',
                    options: $allowedCaches,
                    default: CacheDriver::REDIS->value,
                );
            $cacheDriver = CacheDriver::from($cacheValue);
        }

        // 5. StorageDriver (plan §2d)
        $allowedStorages = collect(StorageDriver::cases())
            ->mapWithKeys(fn ($s) => [$s->value => $s->getLabel()])
            ->all();

        $storageValue = $this->option('fast')
            ? StorageDriver::MINIO->value
            : select(
                label: 'Which S3-compatible object storage would you like to use for Statamic assets?',
                options: array_merge(['none' => 'None (local filesystem)'], $allowedStorages),
                default: StorageDriver::MINIO->value,
            );
        $objectStorage = StorageDriver::tryFrom($storageValue);

        // 6. SearchDriver — all three via Scout (plan §2c)
        $allowedSearch = collect(SearchDriver::cases())
            ->mapWithKeys(fn ($s) => [$s->value => $s->getLabel()])
            ->all();

        $searchValue = $this->option('fast')
            ? 'none'
            : select(
                label: 'Which search driver would you like to use?',
                options: array_merge(['none' => 'None'], $allowedSearch),
                default: 'none',
            );
        $scoutDriver = SearchDriver::tryFrom($searchValue);

        // Apply the driver choices to the config built above.
        $config->setDatabase($database);
        $config->setCacheDriver($cacheDriver);
        if ($objectStorage) {
            $config->setObjectStorage($objectStorage);
        }
        if ($scoutDriver) {
            $config->setScoutDriver($scoutDriver);
        }

        // 7. The Control Panel login, asked now so the site is usable the moment it's up.
        $this->superUser = $this->resolveSuperUser();

        $this->laraKubeInfo("Scaffolding Statamic: $appName...");

        // 8. The official Statamic CLI, inside the builder image
        $this->runStatamicNew($appName, $config, $projectPath);

        if (! is_dir($projectDir)) {
            $this->laraKubeError('Failed to create Statamic application.');

            return 1;
        }

        $this->adoptProjectRequirements($config, $projectDir);

        // 8. Generate K8s manifests
        $this->withSpin('Orchestrating Statamic infrastructure manifests...', function () use ($config): void {
            $this->orchestrateProjectScaffolding($config);
        });

        // Join the Commons AFTER the project exists — see NewCommand.
        if (! $this->option('no-plex')) {
            $this->joinPlexCommons($config, $projectDir);
        }

        $this->laraKubeInfo("✅ Statamic project '$appName' created successfully!");
        $this->newLine();
        if (confirm('Would you like to start your Statamic application now with `larakube up`?', true)) {
            chdir($projectDir);

            return $this->call('up');
        }

        $this->line('  <fg=gray>To start your Statamic application:</>');
        $this->line("  <fg=yellow>cd $appName && larakube up</>");
        $this->newLine();
        if ($this->superUser === null) {
            $this->line('  <fg=gray>To create your first super user, run:</>');
            $this->line('  <fg=yellow>larakube art make:statamic-user</>');
            $this->newLine();
        }
        $this->line('  <fg=gray>Ready to deploy? Create a cloud environment first:</>');
        $this->line('  <fg=yellow>larakube env production</> <fg=gray>(or</> <fg=yellow>larakube cloud:configure</><fg=gray>)</>');
        $this->renderStarPrompt();

        return 0;
    }

    /** The lowest supported PHP version satisfying composer.json's `php` constraint floor, or null. */
    public static function requiredPhpVersion(string $composerJson): ?PhpVersion
    {
        $require = json_decode((string) @file_get_contents($composerJson), true)['require']['php'] ?? null;
        if (! is_string($require) || preg_match('/(\d+)\.(\d+)/', $require, $m) !== 1) {
            return null;
        }

        return PhpVersion::tryFrom("{$m[1]}.{$m[2]}");
    }

    /** Why $password is too weak for a login that ships to production, or null. */
    public static function weakPasswordReason(string $password, string $email): ?string
    {
        $local = strtolower((string) strstr($email, '@', true));

        return match (true) {
            strlen($password) < 12 => 'Use at least 12 characters.',
            in_array(strtolower($password), ['password1234', '123456789012', 'qwertyuiop12', 'letmein12345', 'administrator'], true) => 'That password is too common.',
            $local !== '' && str_contains(strtolower($password), $local) => "Don't include your email name in the password.",
            count(array_unique(str_split($password))) < 6 => 'Use more varied characters.',
            default => null,
        };
    }

    /**
     * Scaffold with the official Statamic CLI (`statamic new`) inside the builder
     * image, so starter kits install the way Statamic documents them. Node and
     * Bun are added first: a kit's post-install hook may run either.
     */
    protected function runStatamicNew(string $appName, ConfigData $config, string $baseDir): void
    {
        $image = $config->getPhpImage(true); // CLI image
        $runtime = $this->containerRuntime();

        $this->laraKubeInfo("Pulling builder image: $image...");
        Process::forever()->run($this->pullImageCommand($image));

        // `statamic new` ends by booting the app, and Intervention's GD driver
        // throws when gd is absent; the generated Dockerfile doesn't exist yet.
        $extensions = $config->getAllPhpExtensions();
        $setup = implode(' && ', array_filter([
            $extensions === [] ? null : 'install-php-extensions '.implode(' ', $extensions),
            $this->getNodeInstallationCommand($image),
            'npm install -g bun',
            'composer config -g bin-dir /usr/local/bin',
            'composer global require '.self::STATAMIC_CLI,
            $this->statamicNewCommand($appName),
        ]));

        $interactive = stream_isatty(STDIN) && stream_isatty(STDOUT);
        $this->runInstaller(
            "$runtime run --rm ".($interactive ? '-it ' : '')."-v $baseDir:/var/www/html"
            .' -e COMPOSER_CACHE_DIR=/dev/null -e COMPOSER_ALLOW_SUPERUSER=1 -e SHOW_WELCOME_MESSAGE=false'
            ." --user root $image sh -c ".escapeshellarg($setup),
            $interactive,
        );

        if (! is_dir("$baseDir/$appName")) {
            return;
        }

        if ($this->superUser !== null) {
            $this->createSuperUser("$baseDir/$appName", $image);
        }

        $this->runStreaming(
            "$runtime run --rm -v $baseDir:/var/www/html --user root -e SHOW_WELCOME_MESSAGE=false $image chown -R {$this->containerChownSpec($this->hostUid(), $this->hostGid())} /var/www/html/$appName",
        );
    }

    /** `statamic new` with the kit and the official flags this command forwards. */
    protected function statamicNewCommand(string $appName): string
    {
        $parts = ['statamic', 'new', $appName];
        if ($this->starterKit !== null) {
            $parts[] = $this->starterKit;
        }

        foreach (['pro', 'with-config', 'without-dependencies'] as $flag) {
            if ($this->option($flag)) {
                $parts[] = "--{$flag}";
            }
        }

        if (($license = (string) $this->option('license')) !== '') {
            $parts[] = '--license='.$license;
        }

        // Our wizard already asked everything; the CLI must not ask again.
        $parts[] = '--no-interaction';
        $parts[] = '--no-ascii-art';

        return implode(' ', array_map(fn (string $part) => preg_match('~^[A-Za-z0-9_./:=@-]+$~', $part) === 1 ? $part : escapeshellarg($part), $parts));
    }

    /** Run the installer in the terminal when there is one, so paid-kit prompts reach the user. */
    protected function runInstaller(string $command, bool $interactive): void
    {
        Process::forever()->tty($interactive && Process::isTtySupported())->run($command, function (string $type, string $output): void {
            $this->output->write($output);
        });
    }

    /**
     * Create the super user with Statamic's own API inside the builder image. The
     * credentials reach the container as environment variables, never argv.
     */
    protected function createSuperUser(string $projectDir, string $image): void
    {
        $script = '.larakube-super-user.php';
        file_put_contents("$projectDir/$script", <<<'PHP'
            <?php
            require __DIR__.'/vendor/autoload.php';
            $app = require __DIR__.'/bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            $email = (string) getenv('LARAKUBE_SU_EMAIL');
            $user = Statamic\Facades\User::findByEmail($email) ?? Statamic\Facades\User::make()->email($email);
            $user->password((string) getenv('LARAKUBE_SU_PASSWORD'))->makeSuper()->save();
            PHP);

        $result = Process::env([
            'LARAKUBE_SU_EMAIL' => $this->superUser['email'],
            'LARAKUBE_SU_PASSWORD' => $this->superUser['password'],
        ])->run(
            "{$this->containerRuntime()} run --rm -v $projectDir:/var/www/html -w /var/www/html"
            ." -e LARAKUBE_SU_EMAIL -e LARAKUBE_SU_PASSWORD -e SHOW_WELCOME_MESSAGE=false --user root $image php $script",
        );
        @unlink("$projectDir/$script");

        if ($result->successful()) {
            $this->laraKubeInfo("Super user {$this->superUser['email']} created.");
        } else {
            $this->laraKubeWarn('Could not create the super user; run `larakube art make:statamic-user` once the site is up.');
            $this->superUser = null;
        }
    }

    /**
     * Take the package manager and PHP version the installed site actually
     * needs: a starter kit may bring its own lockfile (Bedrock uses Bun) and a
     * higher PHP floor than the wizard's choice.
     */
    protected function adoptProjectRequirements(ConfigData $config, string $projectDir): void
    {
        $packageManager = PackageManager::detect($projectDir);
        if ($packageManager !== $config->getPackageManager()) {
            $config->setPackageManager($packageManager);
            $this->laraKubeInfo("Using {$packageManager->value}, the package manager this site ships with.");
        }

        $required = self::requiredPhpVersion("$projectDir/composer.json");
        if ($required !== null && version_compare($required->value, $config->phpVersion->value, '>')) {
            $config->phpVersion = $required;
            $this->laraKubeInfo("Using PHP {$required->value}, which this site requires.");
        }
    }

    /** --starter-kit, or (interactively) ask; blank means the plain Statamic site. */
    protected function resolveStarterKit(): ?string
    {
        $kit = trim((string) $this->option('starter-kit'));
        if ($kit === '' && ! $this->option('fast') && ! $this->option('no-interaction')) {
            $kit = trim(text(
                label: 'Starter kit to install (vendor/kit), or leave blank for none',
                placeholder: 'jasonbaciulis/bedrock',
                hint: 'Browse kits at statamic.com/starter-kits',
            ));
        }

        if ($kit !== '' && preg_match('~^[a-z0-9_.-]+/[a-z0-9_.-]+$~i', $kit) !== 1) {
            throw new InvalidArgumentException("'{$kit}' isn't a starter kit name; use vendor/kit.");
        }

        return $kit === '' ? null : $kit;
    }

    /**
     * The Control Panel super user, or null to skip. The user file is committed
     * with the site, so this becomes a production login: a weak password is refused.
     *
     * @return array{email: string, password: string}|null
     */
    protected function resolveSuperUser(): ?array
    {
        if ($this->option('no-user')) {
            return null;
        }

        $unattended = (bool) $this->option('no-interaction');
        $email = trim((string) $this->option('email'));

        if ($email === '' && $unattended) {
            return null;
        }

        if ($email === '') {
            $email = trim(text(
                label: 'Super user email (your Control Panel login)',
                required: true,
                validate: fn (string $value) => filter_var($value, FILTER_VALIDATE_EMAIL) ? null : 'Enter a valid email address.',
            ));
        }

        $password = $unattended ? (string) getenv('LARAKUBE_STATAMIC_PASSWORD') : '';
        if ($password === '' && $unattended) {
            $this->laraKubeWarn('No LARAKUBE_STATAMIC_PASSWORD set; skipping the super user.');

            return null;
        }

        if (! $unattended) {
            $this->laraKubeLine('  <fg=gray>Users are saved with the site and deployed with it, so this is also your production login.</>');
            $password = password(
                label: 'Super user password',
                required: true,
                validate: fn (string $value) => self::weakPasswordReason($value, $email),
            );
        } elseif (($reason = self::weakPasswordReason($password, $email)) !== null) {
            throw new InvalidArgumentException("LARAKUBE_STATAMIC_PASSWORD: {$reason}");
        }

        return ['email' => $email, 'password' => $password];
    }
}
