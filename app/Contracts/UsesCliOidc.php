<?php

namespace App\Contracts;

/** Marker: OIDC is registered via an exec'd CLI command inside the pod (e.g. `forgejo admin auth add-oauth`), not env-var wiring. */
interface UsesCliOidc {}
