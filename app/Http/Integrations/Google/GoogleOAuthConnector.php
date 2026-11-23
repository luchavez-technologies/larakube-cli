<?php

namespace App\Http\Integrations\Google;

use Saloon\Http\Connector;
use Saloon\Traits\Plugins\AcceptsJson;

class GoogleOAuthConnector extends Connector
{
    use AcceptsJson;

    public function resolveBaseUrl(): string
    {
        return 'https://oauth2.googleapis.com';
    }
}
