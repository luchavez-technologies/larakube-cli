<?php

namespace App\Traits;

use App\Data\ConfigData;

trait InteractsWithDocker
{
    use InteractsWithProjectConfig;

    /**
     * Get the base Docker run command for a specific type (php or node).
     */
    protected function getDockerCommand(string $path, string $type = 'php', string $envs = ''): string
    {
        if ($type === 'node') {
            return "docker run --rm --init -v $path:/usr/src/app -w /usr/src/app --user root $envs -e npm_config_cache=/tmp/.npm node:22-alpine ";
        }

        $appName = basename($path);
        $localImage = "$appName:latest";

        // Check if we have a local image, otherwise fallback to base
        $imageExists = shell_exec("docker images -q {$localImage} 2>/dev/null");
        $image = $imageExists ? $localImage : $this->getProjectConfig($path)->getPhpImage(true);

        $baseEnvs = '-e COMPOSER_CACHE_DIR=/dev/null -e COMPOSER_ALLOW_SUPERUSER=1 -e COMPOSER_IGNORE_PLATFORM_REQS=1';

        return "docker run --rm --init -v $path:/var/www/html -w /var/www/html --user root $baseEnvs $envs {$image} ";
    }

    protected function imageExists(string $image): bool
    {
        $id = shell_exec('docker images -q '.escapeshellarg($image).' 2>/dev/null');

        return ! empty(trim($id ?? ''));
    }

    /**
     * Build the local project image.
     */
    protected function buildImage(ConfigData $config): void
    {
        $appName = $config->getName();
        $path = $config->getPath();

        $imageTag = "$appName:latest";
        $this->laraKubeInfo("Building local image '$imageTag'...");

        $target = '';
        $buildArgs = '';

        if (file_exists("$path/Dockerfile.php")) {
            $content = file_get_contents("$path/Dockerfile.php");
            if (str_contains($content, 'AS development')) {
                $target = '--target development';

                $uid = function_exists('posix_getuid') ? posix_getuid() : 1000;
                $gid = function_exists('posix_getgid') ? posix_getgid() : 1000;
                $buildArgs = "--build-arg USER_ID=$uid --build-arg GROUP_ID=$gid";
            }
        }

        passthru("docker build $target $buildArgs -t $imageTag -f $path/Dockerfile.php $path");

        // --- 🛡 K3D IMAGE BRIDGE ---
        // If k3d cluster exists, import the image so the pods can see it
        $clusters = shell_exec('k3d cluster list --no-headers 2>/dev/null');
        if (str_contains($clusters ?? '', 'larakube')) {
            $this->laraKubeInfo("Importing '$imageTag' into k3d cluster...");
            passthru("k3d image import $imageTag -c larakube");

            // Verify the import
            $this->withSpin('Verifying cluster image availability...', function () use ($imageTag) {
                $images = shell_exec('k3d image list 2>/dev/null');

                return str_contains($images ?? '', $imageTag);
            });
        }
    }

    /**
     * Get the PHP image string based on project config.
     */
    protected function getProjectPhpImage(string $path): string
    {
        $configPath = $path.'/.larakube.json';
        $phpVersion = '8.5';
        $osSuffix = '-alpine';

        if (file_exists($configPath)) {
            $config = json_decode(file_get_contents($configPath), true);
            $phpVersion = $config['phpVersion'] ?? $phpVersion;
            $os = $config['os'] ?? 'alpine';
            $osSuffix = $os === 'alpine' ? '-alpine' : '';
        }

        return "serversideup/php:{$phpVersion}-cli{$osSuffix}";
    }

    /**
     * Get the command to install Node.js and NPM based on the image OS.
     */
    protected function getNodeInstallationCommand(string $image): string
    {
        return str_contains($image, 'alpine')
            ? 'apk add --no-cache nodejs npm'
            : 'apt-get update && apt-get install -y nodejs npm';
    }

    /**
     * Run a command inside a Docker container.
     */
    protected function runInContainer(string $command, string $path, string $type = 'php', string $envs = ''): void
    {
        $base = $this->getDockerCommand($path, $type, $envs);
        passthru($base."sh -c '$command'");
    }

    /**
     * Fix file ownership in the project directory back to the host user.
     */
    protected function chownToHostUser(string $path): void
    {
        $uid = function_exists('posix_getuid') ? posix_getuid() : 1000;
        $gid = function_exists('posix_getgid') ? posix_getgid() : 1000;

        $appName = basename($path);
        $image = "$appName:latest";

        // Fallback if image doesn't exist
        $imageExists = shell_exec("docker images -q {$image} 2>/dev/null");
        if (! $imageExists) {
            $image = $this->getProjectConfig($path)->getPhpImage(true);
        }

        passthru("docker run --rm --init -v $path:/var/www/html -w /var/www/html --user root $image chown -R $uid:$gid .");
    }
}
