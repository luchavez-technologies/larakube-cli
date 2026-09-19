<?php

namespace App\Traits;

use App\Data\ConfigData;

/**
 * Statamic content and users in the project's database instead of flat files.
 *
 * Content goes through Statamic's own `install:eloquent-driver`. Users have no
 * command in Statamic 6, so the documented steps are applied to the project
 * files: each edit anchors on the skeleton's exact text and is skipped (and
 * reported) rather than guessed when a starter kit has changed it.
 *
 * Needs RunsKubectlSteps, InteractsWithEnvironments and LaraKubeOutput.
 */
trait ManagesStatamicDatabase
{
    /**
     * Switch the site's users to the database (config + User model). Returns the
     * files that couldn't be edited; empty means users will live in the database.
     *
     * @return list<string>
     */
    protected function useDatabaseUsers(string $projectDir): array
    {
        $edits = [
            'config/statamic/users.php' => [
                ["'repository' => 'file',", "'repository' => 'eloquent',"],
            ],
            'config/auth.php' => [
                [
                    "        'users' => [\n            'driver' => 'statamic',\n        ],",
                    "        'users' => [\n            'driver' => 'eloquent',\n            'model' => App\\Models\\User::class,\n        ],",
                ],
            ],
            'app/Models/User.php' => [
                ["            'password' => 'hashed',\n", "            'password' => 'hashed',\n            'preferences' => 'json',\n            'two_factor_confirmed_at' => 'datetime',\n"],
            ],
        ];

        // Validate every anchor before touching anything, so a half-edited site can't happen.
        $failed = [];
        foreach ($edits as $file => $pairs) {
            $content = @file_get_contents("{$projectDir}/{$file}");
            foreach ($pairs as [$from]) {
                if ($content === false || ! str_contains($content, $from)) {
                    $failed[] = $file;
                }
            }
        }

        if ($failed !== []) {
            return array_values(array_unique($failed));
        }

        foreach ($edits as $file => $pairs) {
            $content = (string) file_get_contents("{$projectDir}/{$file}");
            foreach ($pairs as [$from, $to]) {
                $content = str_replace($from, $to, $content);
            }
            file_put_contents("{$projectDir}/{$file}", $content);
        }

        // Password resets must send Statamic's notification, not Laravel's.
        $model = (string) file_get_contents("{$projectDir}/app/Models/User.php");
        if (! str_contains($model, 'sendPasswordResetNotification')) {
            $model = (string) preg_replace('/\n}\s*$/', "\n\n    public function sendPasswordResetNotification(\$token): void\n    {\n        \$this->notify(new \\Statamic\\Notifications\\PasswordReset(\$token));\n    }\n}\n", $model, 1);
            file_put_contents("{$projectDir}/app/Models/User.php", $model);
        }

        return [];
    }

    /**
     * In the environment's running web pod: content into the database
     * (Statamic's Eloquent driver, importing the site's files), the users
     * tables, migrations, then the super user. Safe to re-run: the driver
     * skips repositories already moved.
     *
     * @param  array{email: string, password: string}|null  $superUser
     */
    protected function setUpStatamicDatabase(?ConfigData $config, string $environment, ?array $superUser): bool
    {
        if (($cluster = $this->environmentCluster($config, $environment)) === null) {
            return false;
        }

        $namespace = $this->getNamespace($environment, $config?->getName());
        $pod = trim($cluster->raw(['get', 'pods', '-n', $namespace, '-l', 'app=web', '-o', 'jsonpath={.items[0].metadata.name}'])->output);
        if ($pod === '') {
            $this->laraKubeError("No running web pod in {$namespace}. Run `larakube up".($environment === 'local' ? '' : " {$environment}").'` first.');

            return false;
        }

        $inPod = fn (string $script, int $timeout = 120, ?string $stdin = null) => $cluster->exec($namespace, $pod, ['sh', '-c', $script], $stdin, 'php', $timeout);

        $ok = $this->kubectlStep('Moving content into the database (Statamic Eloquent driver)...', fn () => $inPod(
            'php please install:eloquent-driver --all --import --without-messages --no-interaction',
            900,
        ))
            && $this->kubectlStep('Adding the users tables...', fn () => $inPod(
                // auth:migration writes a new file every run; only once per site.
                "grep -rqs 'two_factor_confirmed_at' database/migrations || php please auth:migration --no-interaction",
            ))
            && $this->kubectlStep('Running migrations...', fn () => $inPod('php artisan migrate --force --no-interaction', 300));

        if ($ok && $superUser !== null) {
            $ok = $this->kubectlStep("Creating super user {$superUser['email']}...", fn () => $inPod(
                'php -r '.escapeshellarg(self::statamicSuperUserScript()),
                120,
                (string) json_encode($superUser),
            ));
        }

        return $ok;
    }

    /** PHP that creates (or updates) a Statamic super user from JSON on stdin, so the password is never in argv. */
    protected static function statamicSuperUserScript(): string
    {
        return <<<'PHP'
            require 'vendor/autoload.php';
            $app = require 'bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            $in = json_decode(stream_get_contents(STDIN), true);
            $user = Statamic\Facades\User::findByEmail($in['email']) ?? Statamic\Facades\User::make()->email($in['email']);
            $user->password($in['password'])->makeSuper()->save();
            PHP;
    }
}
