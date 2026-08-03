<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Enums\DatabaseDriver;
use App\Enums\LaravelFeature;
use App\Enums\SearchDriver;
use Illuminate\Support\Str;

trait GeneratesBundleSecrets
{
    /**
     * Generate secure, per-install unique secrets to replace the hardcoded
     * local dev defaults. Returns an array of ENV variables suitable for
     * the laravel-secrets Secret.
     */
    public function generateInstallSecrets(ConfigData $config, string $environment, array $existing = []): array
    {
        $secrets = [];

        // 1. App Key (always needed)
        $secrets['APP_KEY'] = $existing['APP_KEY'] ?? 'base64:'.base64_encode(random_bytes(32));

        // 2. Database Password
        $dbDriver = $config->getDatabase();
        if ($dbDriver && $dbDriver !== DatabaseDriver::SQLITE) {
            $dbPassword = $existing['DB_PASSWORD'] ?? Str::random(32);
            $secrets['DB_PASSWORD'] = $dbPassword;

            if ($dbDriver === DatabaseDriver::MONGODB) {
                $host = $config->getInternalFqdn($dbDriver, $environment);
                $secrets['DB_URI'] = $existing['DB_URI'] ?? "mongodb://root:{$dbPassword}@{$host}:27017/laravel?authSource=admin";
            }
        }

        // 3. Reverb Keys
        if ($config->hasFeature(LaravelFeature::REVERB, $environment)) {
            $secrets['REVERB_APP_ID'] = $existing['REVERB_APP_ID'] ?? (string) random_int(100000, 999999);
            $secrets['REVERB_APP_KEY'] = $existing['REVERB_APP_KEY'] ?? Str::random(32);
            $secrets['REVERB_APP_SECRET'] = $existing['REVERB_APP_SECRET'] ?? Str::random(32);
        }

        // 4. Object Storage (MinIO / SeaweedFS / Garage)
        $storage = $config->getObjectStorage();
        if ($storage) {
            $secrets['AWS_SECRET_ACCESS_KEY'] = $existing['AWS_SECRET_ACCESS_KEY'] ?? Str::random(40);
        }

        // 5. Scout (Meilisearch / Typesense)
        $scout = $config->getScoutDriver();
        if ($scout) {
            if ($scout === SearchDriver::MEILISEARCH) {
                $secrets['MEILISEARCH_KEY'] = $existing['MEILISEARCH_KEY'] ?? Str::random(32);
            } elseif ($scout === SearchDriver::TYPESENSE) {
                $secrets['TYPESENSE_API_KEY'] = $existing['TYPESENSE_API_KEY'] ?? Str::random(32);
            }
        }

        return $secrets;
    }
}
