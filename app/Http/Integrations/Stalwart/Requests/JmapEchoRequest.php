<?php

namespace App\Http\Integrations\Stalwart\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;
use Saloon\Traits\Plugins\HasTimeout;

/**
 * JMAP's spec'd Core/echo method — a no-op that just returns its own
 * arguments, meant purely for testing round-trip connectivity. The
 * prototype request for proving the port-forward + SaloonPHP combination
 * works before converting stalwartJmap()'s kubectl-exec+curl mechanism (and
 * its ~20 callers) wholesale.
 */
class JmapEchoRequest extends Request implements HasBody
{
    use HasJsonBody, HasTimeout;

    protected int $connectTimeout = 15;

    protected int $requestTimeout = 15;

    /**
     * The HTTP method of the request
     */
    protected Method $method = Method::POST;

    public function __construct(protected readonly array $echo = ['hello' => 'world'])
    {
        // Stalwart's JMAP parser 400s (notRequest) on escaped slashes in
        // method names like "Core/echo" — see stalwartJmap()'s docblock.
        $this->body()->setJsonFlags(JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * The endpoint for the request
     */
    public function resolveEndpoint(): string
    {
        return 'jmap';
    }

    protected function defaultBody(): array
    {
        return [
            'using' => ['urn:ietf:params:jmap:core'],
            'methodCalls' => [['Core/echo', $this->echo, 'c1']],
        ];
    }
}
