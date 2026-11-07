<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Enums\DatabaseDriver;

/**
 * What makes a NestJS project deployable: health endpoint, shutdown hooks,
 * and database wiring. Shared by `nestjs:new` and, once NestJS is deployable, `init`.
 */
trait PreparesNestjsProject
{
    /**
     * Add HealthController and register it in AppModule, matching the import
     * style app.module.ts already uses (`./x` or `./x.js` under ESM). Returns
     * false when the module could not be patched safely.
     */
    protected function addNestjsHealthController(string $projectDir): bool
    {
        $module = "{$projectDir}/src/app.module.ts";
        $controller = "{$projectDir}/src/health.controller.ts";

        if (file_exists($controller)) {
            return true;
        }

        $content = @file_get_contents($module);
        if ($content === false
            || ! preg_match("/import \\{ AppController \\} from '\\.\\/app\\.controller(\\.js)?';/", $content, $import)
            || ! str_contains($content, 'controllers: [AppController]')) {
            $this->laraKubeWarn('Could not register a /healthz endpoint in src/app.module.ts — add one returning 200 so the health probes pass.');

            return false;
        }

        file_put_contents($controller, <<<'TS'
import { Controller, Get } from '@nestjs/common';

// Readiness and liveness probe target.
@Controller('healthz')
export class HealthController {
  @Get()
  check() {
    return { status: 'ok' };
  }
}

TS);

        $suffix = $import[1] ?? '';
        $content = str_replace(
            $import[0],
            $import[0]."\nimport { HealthController } from './health.controller{$suffix}';",
            $content,
        );
        $content = str_replace('controllers: [AppController]', 'controllers: [AppController, HealthController]', $content);
        file_put_contents($module, $content);

        $this->laraKubeInfo('Added a /healthz endpoint (src/health.controller.ts).');

        return true;
    }

    /**
     * Enable shutdown hooks in main.ts so NestJS finishes in-flight requests and
     * disconnects from the database cleanly when SIGTERM is received.
     */
    protected function enableNestjsShutdownHooks(string $projectDir): bool
    {
        $main = "{$projectDir}/src/main.ts";
        if (! file_exists($main)) {
            return false;
        }

        $content = @file_get_contents($main);
        if ($content === false || str_contains($content, 'enableShutdownHooks')) {
            return true;
        }

        $pattern = '/(const\s+app\s*=\s*await\s+NestFactory\.create[^\;]*;)/';
        if (! preg_match($pattern, $content, $matches)) {
            return false;
        }

        $replacement = $matches[1]."\n  app.enableShutdownHooks();";
        file_put_contents($main, str_replace($matches[1], $replacement, $content));
        $this->laraKubeInfo('Enabled NestJS shutdown hooks for clean Kubernetes rolling updates.');

        return true;
    }

    /**
     * Scaffold Prisma into the NestJS project with the chosen datasource provider.
     */
    protected function scaffoldNestjsPrisma(string $projectDir, DatabaseDriver $database): void
    {
        if (file_exists("{$projectDir}/prisma/schema.prisma")) {
            return;
        }

        $provider = match ($database) {
            DatabaseDriver::SQLITE => 'sqlite',
            DatabaseDriver::POSTGRESQL => 'postgresql',
            default => 'mysql',
        };

        $this->laraKubeInfo("Scaffolding Prisma ($provider datasource)...");
        $this->runInNodeContainer(
            $projectDir,
            "npm install prisma@6 @prisma/client@6 && npx --yes prisma@6 init --datasource-provider {$provider}",
        );
    }

    /**
     * Seed DATABASE_URL in .env based on the database driver chosen.
     */
    protected function wireNestjsDatabaseEnv(ConfigData $config, string $projectDir, DatabaseDriver $database): void
    {
        if ($database === DatabaseDriver::SQLITE) {
            $this->syncEnvFile($projectDir, [
                'DATABASE_URL' => 'file:./dev.db',
            ], false, 'local');

            return;
        }

        $scheme = $database === DatabaseDriver::POSTGRESQL ? 'postgresql' : 'mysql';
        $dbName = $config->getName();
        $dbHost = $config->getName().'-'.$database->value;
        $port = $database->dbPort();
        $password = bin2hex(random_bytes(16));

        $this->syncEnvFile($projectDir, [
            'DATABASE_URL' => "{$scheme}://{$dbName}:{$password}@{$dbHost}:{$port}/{$dbName}",
            'REDIS_URL' => 'redis://redis:6379',
            'DB_DATABASE' => $dbName,
            'DB_USERNAME' => $dbName,
            'DB_PASSWORD' => $password,
        ], false, 'local');
    }
}
