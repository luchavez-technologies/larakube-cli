<?php

use Symfony\Component\Yaml\Yaml;

test('zitadel manifest renders as valid multi-doc YAML with a working env anchor', function (): void {
    foreach ([false, true] as $noPlex) {
        $rendered = view('k8s.sso.zitadel', [
            'host' => 'sso.example.com',
            'adminEmail' => 'ops@example.com',
            'plexNamespace' => 'larakube-plex',
            'noPlex' => $noPlex,
            'vpnOnly' => false,
            'isLocal' => false,
        ])->render();

        $docs = array_values(array_filter(
            preg_split('/^---$/m', $rendered),
            fn ($d) => trim($d) !== '',
        ));

        $deployment = null;
        foreach ($docs as $doc) {
            $parsed = Yaml::parse($doc);
            expect($parsed)->toBeArray();
            if (($parsed['kind'] ?? null) === 'Deployment') {
                $deployment = $parsed;
            }
        }

        expect($deployment)->not->toBeNull();

        $init = $deployment['spec']['template']['spec']['initContainers'][0];
        $main = $deployment['spec']['template']['spec']['containers'][0];

        // init schema (owner-only) then start-from-setup (no provisioning step).
        expect($init['command'])->toBe(['/app/zitadel', 'init', 'schema']);
        // The YAML anchor must resolve: both containers get the SAME env list.
        expect($main)->toMatchArray(['command' => ['/app/zitadel', 'start-from-setup', '--masterkeyFromEnv', '--tlsMode', 'external'], 'env' => $init['env']]);

        // The admin email is the resolved value (not a synthetic admin@host, and
        // not a literal Blade tag from the old @{{ }} escape bug).
        $email = collect($main['env'])->firstWhere('name', 'ZITADEL_FIRSTINSTANCE_ORG_HUMAN_EMAIL_ADDRESS');
        expect($email['value'])->toBe('ops@example.com');

        // Login V2 must be disabled so the bundled V1 login serves the console.
        $loginV2 = collect($main['env'])->firstWhere('name', 'ZITADEL_DEFAULTINSTANCE_FEATURES_LOGINV2_REQUIRED');
        expect($loginV2['value'])->toBe('false');

        // The PAT output path is the FirstInstance-level key, NOT the (wrong)
        // ORG_MACHINE nesting that silently wrote nothing.
        $names = collect($main['env'])->pluck('name');
        expect($names)->toContain('ZITADEL_FIRSTINSTANCE_PATPATH')
            ->not->toContain('ZITADEL_FIRSTINSTANCE_ORG_MACHINE_PATPATH');

        // A pat-reader sidecar (with a shell) exists to read the PAT the
        // distroless Zitadel container drops on the shared emptyDir.
        $sidecar = collect($deployment['spec']['template']['spec']['containers'])
            ->firstWhere('name', 'pat-reader');
        expect($sidecar)->not->toBeNull();
    }
});
