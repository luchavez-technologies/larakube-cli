<?php

use App\Enums\ClusterTool;

/*
 * sso:wire and mail:wire store a tool's static keys in their Secret and import
 * it with `kubectl set env --from=secret` (ADR 0018), which turns each of those
 * variables into a valueFrom. A manifest that also sets one of them as a plain
 * `value:` can never be re-applied once the tool is wired: kubectl merges the
 * two into an env entry with both, which the API server rejects.
 */
test('no manifest sets a wirer-owned variable as a plain value', function (): void {
    $clashes = [];

    foreach (ClusterTool::cases() as $tool) {
        $keys = array_merge(
            array_keys($tool->oidcEnv()['static'] ?? []),
            array_keys($tool->smtpEnv()['static'] ?? []),
        );
        $directory = resource_path('views/k8s/'.$tool->value);

        if ($keys === [] || ! is_dir($directory)) {
            continue;
        }

        foreach (glob($directory.'/*.blade.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);

            foreach ($keys as $key) {
                if (preg_match('/^( *)- name: '.preg_quote($key, '/').'\n\1  value:/m', $source) === 1) {
                    $clashes[] = basename(dirname($file)).'/'.basename($file).": {$key}";
                }
            }
        }
    }

    expect($clashes)->toBeEmpty();
});
