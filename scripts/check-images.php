<?php

/**
 * Verify that every container image pinned in a k8s manifest template actually
 * exists in its registry.
 *
 * This is not a unit test and deliberately does not live in the Pest suite: it
 * makes real network calls, which the suite forbids. It runs as its own CI job.
 *
 * Why it exists: `stalwartlabs/stalwart:0.16.14` shipped where the registry only
 * publishes `v0.16.14`. Nothing caught it, because a manifest with a bad tag is
 * perfectly valid YAML — it fails at pull time, on the cluster. And since these
 * Deployments run a single replica with `Recreate`, the old pod is already gone
 * by then: a typo'd tag is an OUTAGE, not a failed update.
 *
 * Usage: php scripts/check-images.php [--allow-latest]
 */
$root = dirname(__DIR__);
$allowLatest = in_array('--allow-latest', $argv, true);

/** Images built by LaraKube itself or by the user's own project — not in any public registry. */
const SKIP_PREFIXES = ['luchaveztech/'];

function findImages(string $root): array
{
    $found = [];
    $files = array_merge(
        glob($root.'/resources/views/k8s/*.blade.php') ?: [],
        glob($root.'/resources/views/k8s/*/*.blade.php') ?: [],
        glob($root.'/resources/views/k8s/*/*/*.blade.php') ?: [],
    );

    foreach ($files as $file) {
        foreach (file($file) ?: [] as $n => $line) {
            if (! preg_match('/^\s*image:\s*["\']?([^"\'\s]+)/', $line, $m)) {
                continue;
            }

            // Blade-interpolated tags ({{ $config->getName() }}:latest, version
            // vars) resolve at render time against images that may not be
            // published yet. Nothing to check.
            if (str_contains($m[1], '{{') || str_contains($m[1], '$')) {
                continue;
            }

            $found[$m[1]][] = str_replace($root.'/', '', $file).':'.($n + 1);
        }
    }

    ksort($found);

    return $found;
}

/** Token from a WWW-Authenticate challenge, or the conventional endpoint. */
function registryToken(string $host, string $repo, string $challenge = ''): string
{
    $realm = "https://{$host}/token";
    $params = ['service' => $host, 'scope' => "repository:{$repo}:pull"];

    // A challenge tells us exactly where to go — docker.n8n.io, for instance,
    // is a custom host that delegates to auth.docker.io, so assuming
    // https://<host>/token would ask the wrong server.
    if ($challenge !== '') {
        $parsed = [];
        foreach (explode(',', trim($challenge)) as $part) {
            if (preg_match('/(\w+)="([^"]*)"/', $part, $kv)) {
                $parsed[$kv[1]] = $kv[2];
            }
        }
        if (isset($parsed['realm'])) {
            $realm = $parsed['realm'];
            $params = array_filter([
                'service' => $parsed['service'] ?? null,
                'scope' => $parsed['scope'] ?? "repository:{$repo}:pull",
            ]);
        }
    }

    $body = @file_get_contents($realm.'?'.http_build_query($params));
    $json = json_decode((string) $body, true);

    return $json['token'] ?? $json['access_token'] ?? '';
}

/**
 * HEAD a manifest. Returns [httpCode, wwwAuthenticateValue].
 *
 * @param  list<string>  $accept
 * @return array{0:int,1:string}
 */
function headManifest(string $host, string $repo, string $tag, array $accept, string $token): array
{
    $headers = $accept;
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer '.$token;
    }

    $ch = curl_init("https://{$host}/v2/{$repo}/manifests/".rawurlencode($tag));
    curl_setopt_array($ch, [
        CURLOPT_NOBODY => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $challenge = preg_match('/www-authenticate:\s*Bearer\s+(.+)/i', $raw, $m) ? trim($m[1]) : '';

    return [$code, $challenge];
}

/** @return array{0:bool,1:string} [exists, detail] */
function imageExists(string $image): array
{
    [$name, $tag] = str_contains($image, ':')
        ? [substr($image, 0, strrpos($image, ':')), substr($image, strrpos($image, ':') + 1)]
        : [$image, 'latest'];

    $host = 'registry-1.docker.io';
    $repo = $name;

    if (preg_match('#^([^/]+\.[^/]+)/(.+)$#', $name, $m)) {
        $host = $m[1];
        $repo = $m[2];
    } elseif (! str_contains($repo, '/')) {
        $repo = 'library/'.$repo;   // official image
    }

    $accept = [
        // Without these a multi-arch image answers 404 rather than the index.
        'Accept: application/vnd.oci.image.index.v1+json',
        'Accept: application/vnd.docker.distribution.manifest.list.v2+json',
        'Accept: application/vnd.oci.image.manifest.v1+json',
        'Accept: application/vnd.docker.distribution.manifest.v2+json',
    ];

    // Exactly what a real client does: ask, and only authenticate if told to.
    // Probing some other endpoint for the challenge does not work — GHCR
    // answers /tags/list without any WWW-Authenticate at all, so a token was
    // never fetched and every ghcr.io image read as missing.
    [$code, $challenge] = headManifest($host, $repo, $tag, $accept, '');

    if ($code === 200) {
        return [true, 'HTTP 200'];
    }

    $token = registryToken($host, $repo, $challenge);
    if ($token === '') {
        return [false, "HTTP {$code} (no token)"];
    }

    [$code] = headManifest($host, $repo, $tag, $accept, $token);

    return [$code === 200, "HTTP {$code}"];
}

/**
 * A rate limit or a registry outage is NOT evidence that an image is missing.
 * docker.n8n.io answers 429 under repeated probing; failing CI with "does not
 * exist" on that would be a lie, and the kind that trains people to ignore the
 * job. These are reported and skipped instead.
 */
function inconclusive(string $detail): bool
{
    return (bool) preg_match('/HTTP (429|5\d\d)/', $detail);
}

$images = findImages($root);
$failed = [];
$floating = [];
$skipped = [];

printf("Checking %d pinned images…\n\n", count($images));

foreach ($images as $image => $locations) {
    foreach (SKIP_PREFIXES as $prefix) {
        if (str_starts_with($image, $prefix)) {
            printf("  SKIP    %s (built by LaraKube)\n", $image);

            continue 2;
        }
    }

    if (str_ends_with($image, ':latest') || ! str_contains($image, ':')) {
        $floating[$image] = $locations;
    }

    [$ok, $detail] = imageExists($image);

    if (! $ok && inconclusive($detail)) {
        printf("  SKIP    %s  (%s — rate limited or registry down)\n", $image, $detail);
        $skipped[$image] = $detail;

        continue;
    }

    printf("  %-7s %s%s\n", $ok ? 'OK' : 'MISSING', $image, $ok ? '' : "  ({$detail})");

    if (! $ok) {
        $failed[$image] = $locations;
    }
}

$exit = 0;

if ($failed !== []) {
    echo "\nThese images do not exist in their registry:\n";
    foreach ($failed as $image => $locations) {
        echo "  {$image}\n";
        foreach ($locations as $loc) {
            echo "      {$loc}\n";
        }
    }
    $exit = 1;
}

if ($floating !== [] && ! $allowLatest) {
    echo "\nThese images float on :latest — the running version can change under you,\n";
    echo "and a bad upstream release becomes an outage with no way to roll back:\n";
    foreach ($floating as $image => $locations) {
        echo "  {$image}\n";
        foreach ($locations as $loc) {
            echo "      {$loc}\n";
        }
    }
    $exit = 1;
}

if ($skipped !== []) {
    echo "\nNot verified (registry rate-limited or unavailable) — re-run to confirm:\n";
    foreach ($skipped as $image => $detail) {
        echo "  {$image}  ({$detail})\n";
    }
}

if ($exit === 0) {
    echo "\nAll images resolve and every third-party tag is pinned.\n";
}

exit($exit);
