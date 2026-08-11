<?php

namespace App\Enums;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasCommonsBuckets;
use App\Contracts\HasCommonsDatabases;
use App\Contracts\HasCommonsRedisKeys;
use App\Contracts\HasDbSecretRef;
use App\Contracts\HasOidcWiring;
use App\Contracts\HasOpenbaoSync;
use App\Contracts\HasSmtpWiring;
use App\Contracts\HasWhiteLabel;
use App\Contracts\HasWorkloadComponents;
use App\Contracts\UsesCliOidc;
use App\Data\ClusterToolComponentData;

/** The vendor enum backing ClusterTool::GIT — 'Git Forge & CI/CD'. Only Forgejo today; a future alternative would add a case here. */
enum GitForgeTool: string implements ClusterToolVendor, HasCommonsBuckets, HasCommonsDatabases, HasCommonsRedisKeys, HasDbSecretRef, HasOidcWiring, HasOpenbaoSync, HasSmtpWiring, HasWhiteLabel, HasWorkloadComponents, UsesCliOidc
{
    public function getLabel(): string
    {
        return 'Forgejo';
    }

    public function dbSecretRef(): ?array
    {
        return [
            'secret' => 'forgejo',
            'key' => 'FORGEJO_DB_PASSWORD',
        ];
    }

    public function components(?string $instance = null, ?string $engine = null): array
    {
        $name = fn (string $n) => ($instance === null || $instance === '' || $instance === 'main') ? $n : "{$n}-{$instance}";

        return [
            new ClusterToolComponentData(
                key: 'server',
                role: ClusterToolComponentRole::PRIMARY,
                deployment: $name('forgejo'),
                container: 'forgejo',
                resources: [
                    ['kind' => 'service', 'name' => 'forgejo-http'],
                    ['kind' => 'service', 'name' => 'forgejo-ssh'],
                    ['kind' => 'ingress', 'name' => 'forgejo'],
                    ['kind' => 'pvc', 'name' => 'forgejo-data'],
                    ['kind' => 'secret', 'name' => 'forgejo-admin'],
                ],
                backupVolume: true,
                backupPath: '/data',
            ),
            new ClusterToolComponentData(
                key: 'runner',
                role: ClusterToolComponentRole::WORKER,
                deployment: $name('forgejo-runner'),
            ),
        ];
    }

    public function smtpEnv(?string $instance = null): ?array
    {
        // Forgejo is entirely env-configurable via FORGEJO__<section>__<KEY>;
        // its entrypoint folds them into app.ini on every boot. Keys are
        // the 1.18+ mailer names (PROTOCOL/SMTP_ADDR replaced the old
        // MAILER_TYPE/HOST). `smtps` = implicit TLS, which is Stalwart's
        // 465 submissions listener.
        return [
            'deployment' => 'forgejo',
            'secret' => 'forgejo-smtp',
            'static' => [
                'FORGEJO__mailer__ENABLED' => 'true',
                'FORGEJO__mailer__PROTOCOL' => 'smtps',
            ],
            'vars' => [
                'host' => 'FORGEJO__mailer__SMTP_ADDR',
                'port' => 'FORGEJO__mailer__SMTP_PORT',
                'user' => 'FORGEJO__mailer__USER',
                'password' => 'FORGEJO__mailer__PASSWD',
                'from' => 'FORGEJO__mailer__FROM',
            ],
        ];
    }

    public function oidcEnv(?string $instance = null): ?array
    {
        // CLI-wired (UsesCliOidc): Forgejo keeps login sources in its DB, so
        // there are no env `vars` to set — sso:wire execs
        // `forgejo admin auth add-oauth` instead. The callback path is
        // /user/oauth2/<source name>/callback, and sso:wire names the
        // source `zitadel`.
        return [
            'deployment' => 'forgejo',
            'secret' => 'forgejo-oidc',
            'static' => [],
            'vars' => [],
            'redirect_path' => '/user/oauth2/zitadel/callback',
        ];
    }

    public function commonsDatabaseList(): array
    {
        return ['forgejo'];
    }

    public function commonsBucketList(): array
    {
        return ['forgejo-storage', 'forgejo-packages', 'forgejo-lfs'];
    }

    public function commonsRedisKeys(): array
    {
        return ['forgejo'];
    }

    public function openbaoSyncConfig(): array
    {
        return [
            'secret' => 'forgejo',
            'keys' => ['FORGEJO_DB_PASSWORD'],
        ];
    }

    public function whiteLabel(): array
    {
        return ['app_name_key' => 'FORGEJO__ui__APP_NAME'];
    }
    case FORGEJO = 'forgejo';
}
