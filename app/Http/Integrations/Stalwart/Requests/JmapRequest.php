<?php

namespace App\Http\Integrations\Stalwart\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;
use Saloon\Traits\Plugins\HasTimeout;

class JmapRequest extends Request implements HasBody
{
    use HasJsonBody, HasTimeout;

    protected int $connectTimeout = 15;

    protected int $requestTimeout = 15;

    /**
     * The HTTP method of the request
     */
    protected Method $method = Method::POST;

    /** @param  list<array{0: string, 1: array, 2: string}>  $methodCalls */
    public function __construct(
        protected readonly array $methodCalls,
        protected readonly array $using = ['urn:ietf:params:jmap:core', 'urn:stalwart:jmap'],
    ) {
        // JSON_UNESCAPED_SLASHES matters here: Stalwart's JMAP parser rejects
        // the request outright (400 notRequest) when method names like
        // "x:Account/set" arrive as the default-escaped "x:Account\/set" —
        // valid JSON, but not what its parser expects.
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
            'using' => $this->using,
            'methodCalls' => $this->methodCalls,
        ];
    }
}
