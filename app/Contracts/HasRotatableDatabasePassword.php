<?php

namespace App\Contracts;

/**
 * Marker interface for vendor tools that support dynamic OpenBao static-role
 * database password rotation without breaking application runtime.
 */
interface HasRotatableDatabasePassword extends HasDbSecretRef {}
