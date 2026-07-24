<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as BaseHandler;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Lets an exception render its own console output.
 *
 * Laravel Zero's handler renders every throwable as a stack trace — it never
 * checks whether the exception knows how to present itself. That's fine for
 * genuine bugs, but wrong for the deliberate "you're holding it wrong" errors
 * this CLI raises (MissingFlagException, AmbiguousEnvironmentException): those
 * carry the exact flag and a copy-pasteable example, and burying that under a
 * PHP trace makes an actionable message look like a crash.
 *
 * Anything WITHOUT a renderForConsole() method falls through to the normal
 * trace, so real bugs stay as debuggable as before.
 */
class Handler extends BaseHandler
{
    public function renderForConsole($output, Throwable $e): void
    {
        if (method_exists($e, 'renderForConsole') && $output instanceof OutputInterface) {
            $e->renderForConsole($output);

            return;
        }

        parent::renderForConsole($output, $e);
    }
}
