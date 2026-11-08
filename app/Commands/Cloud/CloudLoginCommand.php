<?php

namespace App\Commands\Cloud;

use App\Enums\CliTool;
use App\Exceptions\MissingFlagException;
use App\Exceptions\OAuthStateMismatchException;
use App\Http\Integrations\Cloudflare\CloudflareConnector;
use App\Http\Integrations\Cloudflare\CloudflareOAuthConnector;
use App\Http\Integrations\Cloudflare\Requests\GetUserRequest;
use App\Http\Integrations\Cloudflare\Requests\TokenExchangeRequest as CloudflareTokenExchangeRequest;
use App\Http\Integrations\DigitalOcean\DigitalOceanConnector;
use App\Http\Integrations\DigitalOcean\DigitalOceanOAuthConnector;
use App\Http\Integrations\DigitalOcean\Requests\GetAccountRequest;
use App\Http\Integrations\DigitalOcean\Requests\TokenExchangeRequest as DigitalOceanTokenExchangeRequest;
use App\Services\BrowserOpener;
use App\Services\Cloud\LoopbackHttpServerInterface;
use App\Services\Cloud\OAuthLoopbackClient;
use App\Services\Cloud\OAuthProviderConfig;
use App\Services\Cloud\OAuthTokenResult;
use App\Traits\EmitsJsonOutput;
use App\Traits\FailsWithJson;
use App\Traits\InteractsWithGlobalConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsCommandOptions;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\StreamsProcessOutput;

use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Process\Process as SymfonyProcess;
use Throwable;

/**
 * Signs in to a cloud provider. Google Cloud wraps `gcloud`'s own sign-in (a terminal gets its normal browser flow;
 * under --json it prints the address on stderr and reads the verification code from stdin). DigitalOcean and
 * Cloudflare are LaraKube's own OAuth: no CLI of their own involved, no client secret (PKCE native-app flow) — a
 * local, short-lived HTTP listener catches the browser's redirect automatically, so there is nothing to type back
 * in either mode.
 */
class CloudLoginCommand extends Command
{
    use EmitsJsonOutput, FailsWithJson, InteractsWithGlobalConfig, LaraKubeOutput, ReadsCommandOptions, RequiresFlagsWhenNonInteractive, StreamsProcessOutput;

    /**
     * LaraKube's own public, secretless native-app OAuth client ids (PKCE,
     * token_endpoint_auth_method: none — see ADR 0029 and
     * cli/plans/active/oauth-provider-connect.md §5). Placeholders until
     * those clients are actually registered; override without a rebuild via
     * LARAKUBE_{PROVIDER}_OAUTH_CLIENT_ID in the meantime.
     */
    private const string DO_OAUTH_CLIENT_ID_PLACEHOLDER = 'larakube-cli-do-pending-registration';

    private const string CLOUDFLARE_OAUTH_CLIENT_ID_PLACEHOLDER = 'larakube-cli-cloudflare-pending-registration';

    protected $signature = 'cloud:login
        {--provider= : The provider to sign in to (gcp, do, cloudflare)}
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = 'Sign in to a cloud provider (Google Cloud, DigitalOcean, Cloudflare)';

    public function handle(): int
    {
        if ($this->flag('json')) {
            $this->enableJsonMode();
        }

        try {
            $provider = $this->flagOrPrompt(
                'provider',
                fn () => select('Which provider?', ['gcp' => 'Google Cloud', 'do' => 'DigitalOcean', 'cloudflare' => 'Cloudflare'], default: 'gcp'),
                'the provider to sign in to',
                '--provider=gcp',
            );
        } catch (MissingFlagException $e) {
            return $this->failed($e->getMessage());
        }

        return match ($provider) {
            'gcp' => $this->signInGcp(),
            'do' => $this->signInDigitalOcean(),
            'cloudflare' => $this->signInCloudflare(),
            default => $this->failed("Signing in from the CLI supports --provider=gcp|do|cloudflare only, not '{$provider}'."),
        };
    }

    protected function signInGcp(): int
    {
        if (! CliTool::GCLOUD->isInstalled()) {
            return $this->failed('Google Cloud CLI (gcloud) is not installed. Run: larakube setup --tools=gcloud');
        }

        $gcloud = CliTool::GCLOUD->resolveBinary() ?? 'gcloud';

        // A person at a terminal gets the browser sign-in; a caller with no terminal gets the address and the code prompt.
        $code = $this->flag('json') || ! SymfonyProcess::isTtySupported()
            ? $this->runWithStdinLine("{$gcloud} auth login --no-launch-browser --update-adc")
            : $this->runInteractive("{$gcloud} auth login --update-adc");

        if ($code !== 0) {
            return $this->failed('Google Cloud sign-in did not finish.');
        }

        if ($this->flag('json')) {
            $this->jsonOutput(['success' => true, 'provider' => 'gcp']);
        } else {
            $this->laraKubeInfo('Signed in to Google Cloud.');
        }

        return 0;
    }

    protected function signInDigitalOcean(): int
    {
        $clientId = getenv('LARAKUBE_DO_OAUTH_CLIENT_ID') ?: self::DO_OAUTH_CLIENT_ID_PLACEHOLDER;

        $providerConfig = new OAuthProviderConfig(
            authorizeUrl: 'https://cloud.digitalocean.com/v1/oauth/authorize',
            tokenUrl: 'https://cloud.digitalocean.com/v1/oauth/token',
            scopes: ['read', 'write'],
            clientId: $clientId,
        );

        return $this->performOAuthLogin(
            providerSlug: 'do',
            providerLabel: 'DigitalOcean',
            providerConfig: $providerConfig,
            exchangeCode: function (string $code, string $verifier, string $redirectUri) use ($clientId): ?array {
                $response = DigitalOceanOAuthConnector::make()->send(
                    new DigitalOceanTokenExchangeRequest($clientId, $code, $verifier, $redirectUri),
                );

                return $response->failed() ? null : (array) $response->json();
            },
            fetchIdentity: function (string $accessToken): ?array {
                $response = DigitalOceanConnector::make($accessToken)->send(new GetAccountRequest);

                if ($response->failed()) {
                    return null;
                }

                return ['id' => (string) $response->json('account.uuid'), 'email' => (string) $response->json('account.email')];
            },
        );
    }

    /**
     * Scopes are DigitalOcean/DNS-management least-privilege, matching what
     * InteractsWithCloudflareApi already needs (zone read + DNS read/write).
     * Cloudflare's OAuth scope catalog is newer (launched 2026-06-03) than
     * its classic API-token permission groups — verify these exact names
     * against GET client/v4/oauth/scopes when the real client is registered.
     */
    protected function signInCloudflare(): int
    {
        $clientId = getenv('LARAKUBE_CLOUDFLARE_OAUTH_CLIENT_ID') ?: self::CLOUDFLARE_OAUTH_CLIENT_ID_PLACEHOLDER;

        $providerConfig = new OAuthProviderConfig(
            authorizeUrl: 'https://dash.cloudflare.com/oauth2/auth',
            tokenUrl: 'https://dash.cloudflare.com/oauth2/token',
            scopes: ['zone.read', 'dns.read', 'dns.write'],
            clientId: $clientId,
        );

        return $this->performOAuthLogin(
            providerSlug: 'cloudflare',
            providerLabel: 'Cloudflare',
            providerConfig: $providerConfig,
            exchangeCode: function (string $code, string $verifier, string $redirectUri) use ($clientId): ?array {
                $response = CloudflareOAuthConnector::make()->send(
                    new CloudflareTokenExchangeRequest($clientId, $code, $verifier, $redirectUri),
                );

                return $response->failed() ? null : (array) $response->json();
            },
            fetchIdentity: function (string $accessToken): ?array {
                $response = CloudflareConnector::make($accessToken)->send(new GetUserRequest);

                if ($response->failed()) {
                    return null;
                }

                return ['id' => (string) $response->json('result.id'), 'email' => (string) $response->json('result.email')];
            },
        );
    }

    /**
     * Shared OAuth-loopback mechanics for every non-gcp provider: open the
     * browser, wait for the local redirect, exchange the code, identify the
     * account, and store it via ADR 0029's upsertOAuthCloudAccount() (keyed
     * on the provider's own account id — never a name the user has to type).
     *
     * @param  callable(string $code, string $codeVerifier, string $redirectUri): ?array  $exchangeCode  returns the decoded token response, or null on failure
     * @param  callable(string $accessToken): ?array{id: string, email: string}  $fetchIdentity  returns null when the provider won't confirm who signed in
     */
    protected function performOAuthLogin(
        string $providerSlug,
        string $providerLabel,
        OAuthProviderConfig $providerConfig,
        callable $exchangeCode,
        callable $fetchIdentity,
    ): int {
        $flow = new OAuthLoopbackClient(app(LoopbackHttpServerInterface::class));
        $pending = $flow->begin($providerConfig);

        $this->laraKubeInfo("Opening your browser to sign in to {$providerLabel}...");
        $this->line("  <fg=blue>{$pending->authorizeUrl}</>");
        BrowserOpener::open($pending->authorizeUrl);

        $authorizationCode = null;
        $stateMismatch = null;

        $this->withSpin('Waiting for you to finish in the browser...', function () use ($flow, $pending, &$authorizationCode, &$stateMismatch): void {
            try {
                $authorizationCode = $flow->awaitCode($pending, 120);
            } catch (OAuthStateMismatchException $e) {
                $stateMismatch = $e->getMessage();
            } finally {
                $flow->close();
            }
        });

        if ($stateMismatch !== null) {
            return $this->failed($stateMismatch);
        }

        if ($authorizationCode === null) {
            return $this->failed("No browser response after 2 minutes. Check it didn't open behind another window, or add an account with a pasted token instead: larakube cloud:accounts --provider={$providerSlug} --add");
        }

        try {
            $tokenBody = $exchangeCode($authorizationCode, $pending->codeVerifier, $pending->redirectUri);
        } catch (Throwable $e) {
            return $this->failed("Could not reach {$providerLabel} to exchange the code — ".$e->getMessage());
        }

        if ($tokenBody === null) {
            return $this->failed("{$providerLabel} refused the sign-in.");
        }

        $result = OAuthTokenResult::fromResponseBody($tokenBody);
        $this->registerSecret($result->accessToken);
        $this->registerSecret($result->refreshToken);

        try {
            $identity = $fetchIdentity($result->accessToken);
        } catch (Throwable $e) {
            return $this->failed("Signed in, but could not identify the {$providerLabel} account — ".$e->getMessage());
        }

        if ($identity === null || $identity['id'] === '') {
            return $this->failed("Signed in, but {$providerLabel} would not confirm which account it was.");
        }

        $globalConfig = $this->getGlobalConfig();
        $globalConfig->upsertOAuthCloudAccount($providerSlug, $identity['id'], "{$providerLabel} — {$identity['email']}", $result);
        $globalConfig->save();

        if ($this->flag('json')) {
            $this->jsonOutput(['success' => true, 'provider' => $providerSlug, 'account' => $identity['email']]);
        } else {
            $this->laraKubeInfo("✅ Signed in to {$providerLabel} as {$identity['email']}.");
        }

        return 0;
    }
}
