<?php

namespace App\Facades;

use App\Services\RuntimeContext;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Facade;

/**
 * Global runtime context facade for LaraKube CLI execution.
 *
 * Backed by the container-scoped App\Services\RuntimeContext singleton.
 * Flushed automatically between test executions by Laravel's test lifecycle.
 *
 * @method static bool isHeaderRendered()
 * @method static RuntimeContext setHeaderRendered(bool $rendered = true)
 * @method static bool isJsonMode()
 * @method static RuntimeContext setJsonMode(bool $jsonMode = true)
 * @method static ?string lastError()
 * @method static RuntimeContext setLastError(?string $lastError)
 * @method static ?OutputStyle stdout()
 * @method static RuntimeContext setStdout(?OutputStyle $stdout)
 * @method static array<string, true> registeredSecrets()
 * @method static RuntimeContext registerSecret(?string $secret)
 * @method static ?string transientDoToken()
 * @method static RuntimeContext setTransientDoToken(?string $token)
 * @method static ?string transientHetznerToken()
 * @method static RuntimeContext setTransientHetznerToken(?string $token)
 * @method static ?string transientCloudflareToken()
 * @method static RuntimeContext setTransientCloudflareToken(?string $token)
 * @method static ?string transientGcpAccount()
 * @method static RuntimeContext setTransientGcpAccount(?string $account)
 * @method static ?string transientGcpProject()
 * @method static RuntimeContext setTransientGcpProject(?string $project)
 * @method static ?string transientGcpCredentials()
 * @method static RuntimeContext setTransientGcpCredentials(?string $credentials)
 * @method static ?string transientAwsProfile()
 * @method static RuntimeContext setTransientAwsProfile(?string $profile)
 * @method static ?string transientAwsRegion()
 * @method static RuntimeContext setTransientAwsRegion(?string $region)
 * @method static ?string transientAwsAccessKeyId()
 * @method static RuntimeContext setTransientAwsAccessKeyId(?string $keyId)
 * @method static ?string transientAwsSecretAccessKey()
 * @method static RuntimeContext setTransientAwsSecretAccessKey(?string $secret)
 * @method static ?string getTransient(string $key, ?string $default = null)
 * @method static RuntimeContext setTransient(string $key, ?string $value)
 * @method static bool hasTransient(string $key)
 * @method static RuntimeContext clearTransients()
 * @method static RuntimeContext flush()
 *
 * @see RuntimeContext
 */
class State extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return RuntimeContext::class;
    }
}
