<?php

namespace App\Traits;

/**
 * What makes a NestJS project deployable: the /healthz endpoint the probes hit.
 * Shared by `nestjs:new` and, once NestJS is deployable, `init`.
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
}
