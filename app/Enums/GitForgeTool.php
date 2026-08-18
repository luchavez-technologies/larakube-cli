<?php

namespace App\Enums;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasAdminEmailPrompt;
use App\Contracts\HasCommonsBuckets;
use App\Contracts\HasCommonsDatabases;
use App\Contracts\HasCommonsRedisKeys;
use App\Contracts\HasDbSecretRef;
use App\Contracts\HasOidcWiring;
use App\Contracts\HasOpenbaoSync;
use App\Contracts\HasPresenceProbe;
use App\Contracts\HasRotatableDatabasePassword;
use App\Contracts\HasSmtpWiring;
use App\Contracts\HasToolAccessDetails;
use App\Contracts\HasVpnWiring;
use App\Contracts\HasWhiteLabel;
use App\Contracts\HasWorkloadComponents;
use App\Contracts\UsesCliOidc;
use App\Data\ClusterToolComponentData;
use Illuminate\Support\Facades\Process;

/** The vendor enum backing ClusterTool::GIT — 'Git Forge & CI/CD'. Only Forgejo today; a future alternative would add a case here. */
enum GitForgeTool: string implements ClusterToolVendor, HasAdminEmailPrompt, HasCommonsBuckets, HasCommonsDatabases, HasCommonsRedisKeys, HasDbSecretRef, HasOidcWiring, HasOpenbaoSync, HasPresenceProbe, HasRotatableDatabasePassword, HasSmtpWiring, HasToolAccessDetails, HasVpnWiring, HasWhiteLabel, HasWorkloadComponents, UsesCliOidc
{
    public function getLabel(): string
    {
        return 'Forgejo';
    }

    public function adminEmailLabel(): string
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
        $name = fn (string $n) => ($instance === null || $instance === '') ? $n : "{$n}-{$instance}";

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
                    ['kind' => 'secret', 'name' => 'git-secrets'],
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

    public function toolAccessRows(?string $host, string $env, string $kubectl, string $instance = ''): array
    {
        $ns = ($instance === null || $instance === '') ? 'larakube-shared' : "larakube-shared-{$instance}";
        $adminPassword = trim(Process::run(
            "{$kubectl} get secret git-secrets -n {$ns} -o jsonpath='{.data.password}' --ignore-not-found",
        )->output());
        $decodedPass = $adminPassword !== '' ? (base64_decode($adminPassword, true) ?: '<unknown>') : '<unknown>';

        return [
            ['SSH Host', $host ? "git@{$host}" : '<unknown>'],
            ['Admin Username', 'larakube'],
            ['Admin Email', 'admin@larakube.local'],
            ['Admin Password', $decodedPass],
        ];
    }

    public function vpnMiddlewareTarget(?string $instance = null): ?array
    {
        $name = ($instance === null || $instance === '') ? 'forgejo-vpn-only' : "forgejo-vpn-only-{$instance}";

        return [
            'name' => $name,
            'namespace' => 'larakube-shared',
        ];
    }

    public function presenceProbe(?string $instance = null): ?string
    {
        $deployment = ($instance === null || $instance === '') ? 'forgejo' : "forgejo-{$instance}";

        return "deployment/{$deployment} -n larakube-shared";
    }
    case FORGEJO = 'forgejo';
}
