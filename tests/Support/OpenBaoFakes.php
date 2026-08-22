<?php

use Illuminate\Support\Str;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;

/**
 * Path-pattern-keyed OpenBao fake, mirroring Http::fake()'s own URL-pattern
 * ergonomics. openBaoApi() collapses every distinct OpenBao endpoint onto
 * just two Saloon Request classes (DynamicRequest/DynamicNoBodyRequest, see
 * their docblocks) — a request class alone can no longer tell Saloon::fake()
 * which OpenBao path is being hit the way Http::fake()'s URL patterns could.
 * This closure inspects the outgoing request's own path and matches it the
 * same way, so an old `'localhost:{wildcard}/v1/sys/init' => Http::response(...)`
 * map mostly just needs its 'localhost:{wildcard}' host prefix dropped to
 * keep working — pass the same map (minus that prefix) as $patternResponses
 * here.
 *
 * Use as the value for BOTH DynamicRequest::class and
 * DynamicNoBodyRequest::class in a Saloon::fake([...]) call when a test's
 * openBaoApi() calls could plausibly be either shape; only one class needs
 * it if the test only ever calls with (or only ever without) a body.
 *
 * @param  array<string, MockResponse|array>  $patternResponses  fnmatch-style path pattern (e.g. '*\/v1\/sys\/init') => response
 * @param  MockResponse|array|null  $default  returned when no pattern matches — mirrors Http::fake()'s own empty-200 behavior for an unmatched request
 */
function openBaoFake(array $patternResponses, MockResponse|array|null $default = null): Closure
{
    return function (PendingRequest $pendingRequest) use ($patternResponses, $default) {
        $path = $pendingRequest->getRequest()->resolveEndpoint();

        foreach ($patternResponses as $pattern => $response) {
            if (Str::is($pattern, $path)) {
                return $response instanceof MockResponse ? $response : MockResponse::make($response);
            }
        }

        return $default instanceof MockResponse ? $default : MockResponse::make($default ?? []);
    };
}
