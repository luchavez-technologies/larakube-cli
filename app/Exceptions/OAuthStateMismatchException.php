<?php

namespace App\Exceptions;

use RuntimeException;

/** The OAuth redirect's `state` didn't match what begin() generated — a forged or replayed callback, never silently accepted. */
class OAuthStateMismatchException extends RuntimeException {}
