<?php

declare(strict_types=1);

/**
 * Publishes standalone binary artifacts to a Forgejo / Gitea release via REST API.
 */
$serverUrl = rtrim((string) (getenv('FORGEJO_URL') ?: getenv('GITHUB_SERVER_URL') ?: 'https://git.luchtech.dev'), '/');
$apiUrl = rtrim((string) (getenv('FORGEJO_API_URL') ?: getenv('GITHUB_API_URL') ?: "{$serverUrl}/api/v1"), '/');
$repo = (string) (getenv('FORGEJO_REPOSITORY') ?: getenv('GITHUB_REPOSITORY') ?: 'luchaveztech/larakube-cli');
$token = (string) (getenv('FORGEJO_TOKEN') ?: getenv('GITHUB_TOKEN') ?: '');
$tag = (string) (getenv('RELEASE_TAG') ?: getenv('GITHUB_REF_NAME') ?: 'canary');
$releaseName = (string) (getenv('RELEASE_NAME') ?: $tag);
$commitSha = (string) (getenv('GITHUB_SHA') ?: '');
$isPrerelease = filter_var(getenv('IS_PRERELEASE') ?: ($tag === 'canary'), FILTER_VALIDATE_BOOLEAN);
$artifactsDir = (string) (getenv('ARTIFACTS_DIR') ?: 'artifacts');

if ($token === '') {
    fwrite(STDERR, "❌ ERROR: No authentication token found (FORGEJO_TOKEN or GITHUB_TOKEN).\n");
    exit(1);
}

echo "🚀 Publishing release to Forgejo:\n";
echo "   Server:     {$serverUrl}\n";
echo "   Repository: {$repo}\n";
echo "   Tag:        {$tag}\n";
echo "   Name:       {$releaseName}\n";
echo '   Prerelease: '.($isPrerelease ? 'yes' : 'no')."\n";
echo "   Artifacts:  {$artifactsDir}\n\n";

/**
 * @param  array<int, string>  $headers
 * @return array{status: int, body: string|false, error: string}
 */
function request(string $method, string $url, ?string $body = null, array $headers = []): array
{
    global $token;
    $ch = curl_init($url);
    $defaultHeaders = [
        "Authorization: token {$token}",
        'User-Agent: LaraKube-Release-Publisher/1.0',
    ];

    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($defaultHeaders, $headers));

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'status' => $status,
        'body' => $response,
        'error' => $error,
    ];
}

// 1. Look up existing release by tag
$res = request('GET', "{$apiUrl}/repos/{$repo}/releases/tags/".rawurlencode($tag));
$releaseId = null;
$existingAssets = [];

if ($res['status'] === 200 && ($data = json_decode((string) $res['body'], true)) && isset($data['id'])) {
    $releaseId = $data['id'];
    $existingAssets = $data['assets'] ?? [];
    echo "Found existing release #{$releaseId} for tag '{$tag}'.\n";
} else {
    echo "Release for tag '{$tag}' does not exist yet. Creating...\n";
    $payload = json_encode([
        'tag_name' => $tag,
        'name' => $releaseName,
        'body' => "### LaraKube Standalone Binaries\n\n- **Version:** `{$tag}`\n- **Commit:** `{$commitSha}`\n- **Date:** ".date('Y-m-d H:i:s T')."\n\nDownload the binary for your platform and place it in `/usr/local/bin/larakube`.",
        'draft' => false,
        'prerelease' => $isPrerelease,
    ]);

    $createRes = request('POST', "{$apiUrl}/repos/{$repo}/releases", (string) $payload, ['Content-Type: application/json']);
    if ($createRes['status'] < 200 || $createRes['status'] >= 300) {
        fwrite(STDERR, "❌ Failed to create release: HTTP {$createRes['status']}\n{$createRes['body']}\n");
        exit(1);
    }

    $created = json_decode((string) $createRes['body'], true);
    $releaseId = $created['id'] ?? null;
    echo "Created release #{$releaseId} for tag '{$tag}'.\n";
}

if (! $releaseId) {
    fwrite(STDERR, "❌ Could not determine release ID.\n");
    exit(1);
}

// 2. Upload artifacts
if (! is_dir($artifactsDir)) {
    fwrite(STDERR, "❌ Artifacts directory '{$artifactsDir}' does not exist.\n");
    exit(1);
}

$files = glob("{$artifactsDir}/*");
if (empty($files)) {
    fwrite(STDERR, "⚠️ No artifact files found in '{$artifactsDir}'.\n");
    exit(0);
}

foreach ($files as $file) {
    if (! is_file($file)) {
        continue;
    }

    $filename = basename($file);
    $filesize = filesize($file);
    $sha256 = hash_file('sha256', $file);

    // Delete existing asset if it has the same name
    foreach ($existingAssets as $asset) {
        if (($asset['name'] ?? '') === $filename && isset($asset['id'])) {
            echo "Removing existing asset #{$asset['id']} ({$filename})...\n";
            request('DELETE', "{$apiUrl}/repos/{$repo}/releases/{$releaseId}/assets/{$asset['id']}");
        }
    }

    echo "Uploading {$filename} (".number_format($filesize / 1024 / 1024, 2).' MB, SHA256: '.substr((string) $sha256, 0, 8)."...)...\n";
    $fileData = (string) file_get_contents($file);

    $uploadUrl = "{$apiUrl}/repos/{$repo}/releases/{$releaseId}/assets?name=".rawurlencode($filename);
    $uploadRes = request('POST', $uploadUrl, $fileData, [
        'Content-Type: application/octet-stream',
    ]);

    if ($uploadRes['status'] >= 200 && $uploadRes['status'] < 300) {
        echo "   ✅ Successfully uploaded {$filename}\n";
    } else {
        fwrite(STDERR, "   ❌ Failed to upload {$filename}: HTTP {$uploadRes['status']}\n{$uploadRes['body']}\n");
        exit(1);
    }
}

echo "\n🎉 All assets published successfully to {$serverUrl}/{$repo}/releases/tag/{$tag}\n";
