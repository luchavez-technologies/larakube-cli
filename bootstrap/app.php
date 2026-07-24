<?php

use App\Exceptions\Handler;
use Illuminate\Contracts\Debug\ExceptionHandler;
use LaravelZero\Framework\Application;

$app = Application::configure(basePath: dirname(__DIR__))->create();

// Our own handler so exceptions that define renderForConsole() present their
// own actionable message instead of a stack trace. See App\Exceptions\Handler.
$app->singleton(ExceptionHandler::class, Handler::class);

return $app;
