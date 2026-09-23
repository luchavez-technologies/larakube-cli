# Eradicate Static `State` Class Refactor Plan

## 1. Problem Statement
The `App\State` class currently uses plain PHP static properties (`public static ?string $transientAwsProfile`, `public static ?string $lastError`, `public static bool $isTesting`, etc.).
Because PHP static properties persist on the class loader across all test executions within a single test runner worker process:
1. Any state modified in one test leaks to subsequent tests unless manually cleared.
2. Every time a new property or transient is added to `State.php`, developers must remember to add a manual reset in `tests/TestCase.php::setUp()`. Forgetting to do so causes subtle, hard-to-debug test flakiness and state contamination (such as mutating the user's host `gcloud` configuration).
3. `State::$isTesting` is an anti-pattern when Laravel already provides `app()->runningUnitTests()`.

## 2. Architectural Solution (The Idiomatic Laravel Way)

### A. `App\Services\RuntimeContext` (Container Singleton)
- Implement `App\Services\RuntimeContext` with typed instance properties, getters, setters, and transient helpers:
  - Output state: `$headerRendered`, `$jsonMode`, `$lastError`, `$stdout`, `$registeredSecrets`
  - Transient credentials: `$transientDoToken`, `$transientHetznerToken`, `$transientCloudflareToken`, `$transientGcpAccount`, `$transientGcpProject`, `$transientGcpCredentials`, `$transientAwsProfile`, `$transientAwsRegion`, `$transientAwsAccessKeyId`, `$transientAwsSecretAccessKey`
  - Generic transient key-value store: `getTransient()`, `setTransient()`, `hasTransient()`, `clearTransients()`
  - Lifecycle helper: `flush()`
- Register `RuntimeContext` as a singleton in `App\Providers\AppServiceProvider`:
  ```php
  $this->app->singleton(RuntimeContext::class);
  ```
- Because Laravel creates a fresh application container and calls `Facade::clearResolvedInstances()` on every single test execution, all state is completely flushed automatically with zero manual resets.

### B. `App\State` (Laravel Facade)
- Convert `App\State` into a standard Laravel Facade extending `Illuminate\Support\Facades\Facade`.
- Override `getFacadeAccessor(): string => RuntimeContext::class`.
- Provide complete `@method` docblocks for static analysis and IDE autocomplete.
- Support static method calls (`State::lastError()`, `State::setLastError(...)`, `State::transientDoToken()`, `State::setTransientDoToken(...)`, `State::isJsonMode()`, etc.).

### C. Native `app()->runningUnitTests()`
- Replace all occurrences of `State::$isTesting` with Laravel's native `app()->runningUnitTests()`.
- In `VpnInitCommand`, replace the hardcoded `State::$isTesting` guard with an overridable method `shouldSkipTlsWait()` and `shouldSkipVpnEndpointWait()` (which defaults to `app()->runningUnitTests()`). This allows unit tests (`VpnInitCommandTest`) to cleanly test retry loops without touching global test flags.

### D. Clean Up `tests/TestCase.php`
- Remove all 14 manual static resets in `tests/TestCase.php::setUp()`.
- Let Laravel's container lifecycle manage runtime context hygiene automatically.

## 3. Implementation Steps

1. **Create `App\Services\RuntimeContext`**:
   - Create `cli/app/Services/RuntimeContext.php`.
   - Register singleton in `cli/app/Providers/AppServiceProvider.php`.

2. **Convert `App\State` to Facade**:
   - Rewrite `cli/app/State.php` to extend `Illuminate\Support\Facades\Facade`.

3. **Update Traits and Commands**:
   - `cli/app/Traits/LaraKubeOutput.php`: update to `State::isHeaderRendered()`, `State::isJsonMode()`, `State::registerSecret()`, `State::registeredSecrets()`, `State::setLastError()`, and replace `State::$isTesting` with `app()->runningUnitTests()`.
   - `cli/app/Traits/EmitsJsonOutput.php`: update to `State::setJsonMode(true)`, `State::setStdout(...)`, `State::stdout()`.
   - `cli/app/Traits/StreamsProcessOutput.php`: update to `State::isJsonMode()`.
   - `cli/app/Traits/InteractsWithGlobalConfig.php`: update to `State::transient*()`.
   - `cli/app/Traits/InteractsWithAws.php`, `InteractsWithGcp.php`, `InteractsWithHetzner.php`: update to `State::setTransient*()` and `State::transient*()`.
   - `cli/app/Commands/Cloud/CloudCreateCommand.php`, `CloudScaleCommand.php`, `CloudDestroyCommand.php`: update to `State::isJsonMode()`, `State::lastError()`, and `State::setTransient*()`.
   - `cli/app/Commands/Cluster/ClusterGrantCommand.php`, `cli/app/Commands/Vpn/VpnGrantCommand.php`: update to `State::isJsonMode()`, `State::lastError()`.
   - `cli/app/Commands/Vpn/VpnInitCommand.php`: replace `State::$isTesting` with `app()->runningUnitTests()` and protected overridable hook.

4. **Update Tests**:
   - Remove manual static resets from `cli/tests/TestCase.php`.
   - Update assertions and setup in test files from `State::$lastError` to `State::lastError()`, `State::$transient*` to `State::transient*()` and `State::setTransient*()`.
   - In `cli/tests/Feature/VpnInitCommandTest.php`, override `shouldSkipTlsWait()` in anonymous command subclass instead of toggling `isTesting`.

5. **Quality Verification**:
   - Run unit tests for `RuntimeContext` and `State` facade.
   - Run Pint (`./vendor/bin/pint --test`).
   - Run PHPStan (`php -d memory_limit=2G ./vendor/bin/phpstan analyse --no-progress`).
   - Run Pest (`./vendor/bin/pest`).
   - Remind user to run `./build`.
