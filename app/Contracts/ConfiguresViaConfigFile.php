<?php

namespace App\Contracts;

/**
 * Marker: this vendor is configured entirely via a mounted config file
 * (e.g. Synapse's homeserver.yaml) and ignores env-var wiring, blocking the
 * normal env-patch wiring path.
 */
interface ConfiguresViaConfigFile {}
