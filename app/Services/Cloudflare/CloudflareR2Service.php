<?php

namespace App\Services\Cloudflare;

use App\Data\GlobalConfigData;
use Illuminate\Support\Facades\Http;

/**
 * Configures Cloudflare R2 as OpenTofu's S3-compatible remote state backend
 * directly from a single Cloudflare API Token (Access Key ID = token ID from
 * `/user/tokens/verify`, Secret Access Key = SHA-256 hex digest of the token).
 */
class CloudflareR2Service
{
    public const DEFAULT_BUCKET = 'larakube-tofu-state';

    public const CREDENTIALS_FILE = '.larakube/r2-credentials';

    /**
     * Inspect a Cloudflare API token for R2 permissions, ensure the state
     * bucket exists, write `~/.larakube/r2-credentials` (0600), and persist
     * remote-state settings in GlobalConfigData.
     *
     * @return array{
     *     configured: bool,
     *     hasR2Permission: bool,
     *     accountId?: string,
     *     bucket?: string,
     *     endpoint?: string,
     *     credentialsFile?: string,
     *     reason?: string,
     * }
     */
    public function detectAndConfigure(string $token, string $bucket = self::DEFAULT_BUCKET, bool $save = true): array
    {
        $token = trim($token);
        if ($token === '') {
            return ['configured' => false, 'hasR2Permission' => false, 'reason' => 'Cloudflare API token is empty.'];
        }

        $verify = Http::withToken($token)->timeout(15)->get('https://api.cloudflare.com/client/v4/user/tokens/verify');
        if (! $verify->successful() || ! ($verify->json('success') === true)) {
            return ['configured' => false, 'hasR2Permission' => false, 'reason' => 'Could not verify Cloudflare API token.'];
        }

        $tokenId = (string) ($verify->json('result.id') ?? '');
        if ($tokenId === '') {
            return ['configured' => false, 'hasR2Permission' => false, 'reason' => 'Cloudflare token verification did not return a token ID.'];
        }

        $accountsResp = Http::withToken($token)->timeout(15)->get('https://api.cloudflare.com/client/v4/accounts');
        $accounts = $accountsResp->json('result');
        if (! $accountsResp->successful() || ! is_array($accounts) || $accounts === []) {
            return ['configured' => false, 'hasR2Permission' => false, 'reason' => 'No Cloudflare account accessible with this token (grant Account → Workers R2 Storage → Edit).'];
        }

        $accountId = (string) ($accounts[0]['id'] ?? '');
        if ($accountId === '') {
            return ['configured' => false, 'hasR2Permission' => false, 'reason' => 'Could not resolve Cloudflare account ID.'];
        }

        $bucketsResp = Http::withToken($token)->timeout(15)->get("https://api.cloudflare.com/client/v4/accounts/{$accountId}/r2/buckets");
        if ($bucketsResp->status() === 401 || $bucketsResp->status() === 403) {
            return [
                'configured' => false,
                'hasR2Permission' => false,
                'accountId' => $accountId,
                'reason' => 'Token lacks Account → Workers R2 Storage → Edit permission.',
            ];
        }

        if (! $bucketsResp->successful()) {
            return [
                'configured' => false,
                'hasR2Permission' => false,
                'accountId' => $accountId,
                'reason' => 'Could not query Cloudflare R2 buckets (HTTP '.$bucketsResp->status().').',
            ];
        }

        $existingBuckets = array_map(
            static fn (array $b): string => (string) ($b['name'] ?? ''),
            array_filter((array) ($bucketsResp->json('result.buckets') ?? $bucketsResp->json('result') ?? []), 'is_array'),
        );

        if (! in_array($bucket, $existingBuckets, true)) {
            $createResp = Http::withToken($token)
                ->timeout(15)
                ->post("https://api.cloudflare.com/client/v4/accounts/{$accountId}/r2/buckets", ['name' => $bucket]);

            // 409 / error 10004 means the bucket already exists globally/under another filter.
            $alreadyExists = $createResp->status() === 409;
            if (! $createResp->successful() && ! $alreadyExists) {
                return [
                    'configured' => false,
                    'hasR2Permission' => true,
                    'accountId' => $accountId,
                    'reason' => 'Could not create R2 bucket "'.$bucket.'" (HTTP '.$createResp->status().').',
                ];
            }
        }

        $secretAccessKey = hash('sha256', $token);
        $endpoint = "https://{$accountId}.r2.cloudflarestorage.com";
        $credentialsFile = $this->writeCredentialsFile($tokenId, $secretAccessKey);

        if ($save) {
            $config = GlobalConfigData::load();
            $config->tofuStateEnabled = true;
            $config->tofuStateProvider = 'r2';
            $config->tofuStateBucket = $bucket;
            $config->tofuStateAccountId = $accountId;
            $config->tofuStateEndpoint = $endpoint;
            $config->tofuStateAccessKeyId = $tokenId;
            $config->tofuStateSecretAccessKey = $secretAccessKey;
            $config->tofuStateApiToken = $token;
            $config->save();
        }

        return [
            'configured' => true,
            'hasR2Permission' => true,
            'accountId' => $accountId,
            'bucket' => $bucket,
            'endpoint' => $endpoint,
            'credentialsFile' => $credentialsFile,
        ];
    }

    public function writeCredentialsFile(string $accessKeyId, string $secretAccessKey): string
    {
        $path = home_path(self::CREDENTIALS_FILE);
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        $contents = "[r2]\naws_access_key_id = {$accessKeyId}\naws_secret_access_key = {$secretAccessKey}\n";
        file_put_contents($path, $contents);
        @chmod($path, 0600);

        return $path;
    }
}
