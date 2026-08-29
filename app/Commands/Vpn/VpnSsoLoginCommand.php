<?php

namespace App\Commands\Vpn;

use App\Http\Integrations\Netbird\NetbirdConnector;
use App\Http\Integrations\Netbird\Requests\CreatePersonalAccessTokenRequest;
use App\Http\Integrations\Netbird\Requests\ListUsersRequest;
use App\Http\Integrations\NetbirdIdp\NetbirdIdpConnector;
use App\Http\Integrations\NetbirdIdp\Requests\DeviceCodeRequest;
use App\Http\Integrations\NetbirdIdp\Requests\DeviceTokenRequest;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\InteractsWithVpn;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsClusterSecrets;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use LaravelZero\Framework\Commands\Command;
use Throwable;

/**
 * Bring the shared SSO account into existence, and wire the CLI into it.
 *
 * NetBird derives an account's email domain from the JWT that creates it, and
 * only an account WITH a domain can be shared by SSO users. Neither route the
 * operator has produces one:
 *
 *   - /api/setup (what vpn:init uses to bootstrap) never sets a domain at all,
 *     and single-account mode then copies that empty domain onto every later
 *     login, minting a fresh isolated account each time.
 *   - The dashboard cannot help either: with zero accounts it hard-gates on its
 *     first-run wizard and never makes an authenticated call, so a browser
 *     sign-in completes at the IdP and dies there. Confirmed live 2026-08-29 —
 *     the login succeeded and management logged nothing at all.
 *
 * So the first authenticated call has to come from here. The device-code grant
 * gets a real person's token without the CLI needing a browser, and the call it
 * then makes is what NetBird creates the account from.
 */
class VpnSsoLoginCommand extends Command
{
    use InteractsWithClusterContext, InteractsWithProjectConfig, InteractsWithVpn, LaraKubeOutput, ReadsClusterSecrets;

    /** Dex registers this static public client itself; it takes no secret. */
    private const CLIENT_ID = 'netbird-dashboard';

    protected $signature = 'vpn:sso-login
        {environment=local : Environment whose NetBird VPN to sign in to}
        {--context=        : Target a specific kube-context}';

    protected $description = 'Sign in through SSO to create the account SSO users share, and store its token';

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');
        $config = $this->getProjectConfig();
        $kubectl = $this->vpnKubectl($this->resolveVpnContext($env, $config));
        $ns = $this->vpnNamespace();

        if (! $this->isVpnInstalled($kubectl, $ns)) {
            $this->laraKubeError('NetBird is not installed. Run `larakube vpn:init` first.');

            return 1;
        }

        $host = $this->resolveVpnHostReadOnly($env, $config);
        if ($host === null) {
            $this->laraKubeError("No NetBird host is configured for '{$env}'.");

            return 1;
        }

        $device = $this->startDeviceLogin($host);
        if ($device === null) {
            return 1;
        }

        $this->laraKubeNewLine();
        $this->line('  <fg=gray>Open this and sign in with Zitadel:</>');
        $this->line('  <fg=blue>'.($device['verification_uri_complete'] ?? $device['verification_uri']).'</>');
        $this->newLine();
        $this->line('  <fg=gray>Code:</> <fg=yellow>'.$device['user_code'].'</>');
        $this->newLine();

        $token = $this->awaitDeviceToken($host, (string) $device['device_code'], (int) ($device['interval'] ?? 5), (int) ($device['expires_in'] ?? 300));
        if ($token === null) {
            return 1;
        }

        // The call that matters: NetBird resolves the account from this token's
        // claims and creates it if there is none.
        $userId = null;
        $created = $this->withSpin('Creating the shared account...', function () use ($host, $token, &$userId): bool {
            try {
                $users = NetbirdConnector::make($host, null, $token)->send(ListUsersRequest::make());

                if ($users->failed()) {
                    return false;
                }

                foreach ((array) $users->json() as $user) {
                    if (($user['is_current'] ?? false) === true) {
                        $userId = (string) ($user['id'] ?? '');
                    }
                }

                return true;
            } catch (Throwable) {
                return false;
            }
        });

        if (! $created) {
            $this->laraKubeError('Signed in, but NetBird would not create the account from that token.');
            $this->line('  <fg=gray>Most often the token carries no verified email claim — check that the Zitadel</>');
            $this->line('  <fg=gray>user has one, since the account domain is derived from it.</>');

            return 1;
        }

        $domain = $this->vpnAccountDomain($host, $token);
        $expected = $this->vpnSsoDomain($host);

        // Must MATCH, not merely exist. NetBird groups new SSO users by looking
        // for an account whose private domain equals the configured
        // single-account domain, so an account stamped with anything else means
        // the next person to sign in gets their own account and their own /16.
        // A bare non-empty check let 'netbird.selfhosted' through once already.
        if ($domain === null || $domain === '') {
            $this->laraKubeError('The account was created without an email domain, so SSO users still cannot share it.');
            $this->line('  <fg=gray>This is the state vpn:init used to leave behind. Retire it with</>');
            $this->line('  <fg=blue>  larakube sso:wire '.$env.' --tool=vpn</> <fg=gray>and sign in again.</>');

            return 1;
        }

        if ($domain !== $expected) {
            $this->laraKubeError("The account was stamped '{$domain}', but this cluster groups users by '{$expected}'.");
            $this->line('  <fg=gray>The next SSO sign-in would land in a separate account. netbird-mgmt takes the</>');
            $this->line('  <fg=gray>domain from its --single-account-mode-domain flag, so check that the management</>');
            $this->line('  <fg=gray>Deployment passes it as an arg — an env var alone is ignored by the binary.</>');

            return 1;
        }

        if ($userId !== null && $userId !== '') {
            $this->storeTokenFor($kubectl, $ns, $host, $token, $userId, $env);
        }

        $this->laraKubeNewLine();
        $this->laraKubeInfo("✅ Account created, owned by the '{$domain}' domain.");
        $this->newLine();
        $this->line('  <fg=gray>Every SSO sign-in from that domain now lands in this same account.</>');
        $this->line('  <fg=gray>Finish with</> <fg=blue>larakube vpn:init '.$env.'</><fg=gray> — it recreates the service user,</>');
        $this->line('  <fg=gray>the groups, and the gateway key against it.</>');
        $this->newLine();

        return 0;
    }

    /** @return array<string, mixed>|null */
    protected function startDeviceLogin(string $host): ?array
    {
        try {
            $response = NetbirdIdpConnector::make($host)->send(DeviceCodeRequest::make(self::CLIENT_ID));

            if ($response->failed()) {
                $this->laraKubeError('The embedded identity provider refused to start a sign-in.');

                return null;
            }

            $data = $response->json();

            return is_array($data) && isset($data['device_code'], $data['user_code']) ? $data : null;
        } catch (Throwable $e) {
            $this->laraKubeError('Could not reach '.$host.' — '.$e->getMessage());

            return null;
        }
    }

    /**
     * Poll until the person finishes signing in. `authorization_pending` is the
     * expected answer throughout, not an error.
     */
    protected function awaitDeviceToken(string $host, string $deviceCode, int $interval, int $expiresIn): ?string
    {
        $deadline = now()->addSeconds(max($expiresIn, 60));
        $token = null;

        $this->withSpin('Waiting for you to sign in...', function () use ($host, $deviceCode, $interval, $deadline, &$token): void {
            while (now()->lessThan($deadline)) {
                try {
                    $response = NetbirdIdpConnector::make($host)->send(DeviceTokenRequest::make(self::CLIENT_ID, $deviceCode));
                    $body = (array) $response->json();

                    if (! empty($body['access_token'])) {
                        $token = (string) $body['access_token'];

                        return;
                    }

                    // Anything other than "still waiting" is terminal — a denied
                    // or expired code will never turn into a token.
                    if (! in_array($body['error'] ?? '', ['authorization_pending', 'slow_down'], true)) {
                        return;
                    }
                } catch (Throwable) {
                    return;
                }

                Sleep::sleep(max($interval, 1));
            }
        });

        if ($token === null) {
            $this->laraKubeError('No sign-in completed before the code expired.');
        }

        return $token;
    }

    /**
     * Mint a PAT as the freshly-created owner and store it, so the CLI is wired
     * into the new account without anyone visiting the dashboard.
     */
    protected function storeTokenFor(string $kubectl, string $ns, string $host, string $bearer, string $userId, string $env): void
    {
        $this->withSpin('Storing a token for this account...', function () use ($kubectl, $ns, $host, $bearer, $userId): void {
            try {
                $response = NetbirdConnector::make($host, null, $bearer)
                    ->send(CreatePersonalAccessTokenRequest::make($userId, 'larakube', 365));

                $pat = $response->failed() ? null : $response->json('plain_token');

                if (! is_string($pat) || $pat === '') {
                    return;
                }

                $this->registerSecret($pat);

                // owner-pat too: this user owns the account, and NetBird reserves
                // deleting an account to its owner.
                Process::run(
                    "{$kubectl} patch secret ".$this->vpnName('vpn-management-secrets', $kubectl)." -n {$ns} --type=merge -p "
                    .escapeshellarg((string) json_encode(['data' => [
                        'pat' => base64_encode($pat),
                        'owner-pat' => base64_encode($pat),
                    ]], JSON_THROW_ON_ERROR)),
                );
            } catch (Throwable) {
                // Best-effort: the account exists either way, which is the point.
            }
        });
    }
}
