<?php

namespace App\Contracts;

/** Marker: this vendor is gated via Traefik ForwardAuth + a shared OAuth2-Proxy instead of native OIDC. */
interface UsesForwardAuth {}
