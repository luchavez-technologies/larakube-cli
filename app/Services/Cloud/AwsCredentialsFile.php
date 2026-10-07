<?php

namespace App\Services\Cloud;

/**
 * Writes one profile into the AWS CLI's own ~/.aws files without touching any other profile in them.
 */
final class AwsCredentialsFile
{
    /** @param  array<string, string>  $values */
    public static function upsert(string $existing, string $section, array $values): string
    {
        $lines = $existing === '' ? [] : (preg_split('/\R/', rtrim($existing, "\r\n")) ?: []);
        $block = ["[{$section}]", ...array_map(fn (string $key, string $value): string => "{$key} = {$value}", array_keys($values), $values)];

        $out = [];
        $inTarget = false;
        $written = false;

        foreach ($lines as $line) {
            if (preg_match('/^\s*\[(.+)\]\s*$/', $line, $match) === 1) {
                $inTarget = trim($match[1]) === $section;

                if ($inTarget) {
                    if (! $written) {
                        array_push($out, ...$block, ...['']);
                        $written = true;
                    }

                    continue;
                }
            } elseif ($inTarget) {
                continue;
            }

            $out[] = $line;
        }

        if (! $written) {
            if ($out !== [] && end($out) !== '') {
                $out[] = '';
            }

            array_push($out, ...$block);
        }

        return rtrim(implode("\n", $out), "\n")."\n";
    }

    /** Writes both files, 0600 inside a 0700 folder, keeping every other profile. */
    public static function save(string $home, string $accessKeyId, string $secretAccessKey, string $region, string $profile = 'default'): void
    {
        $directory = rtrim($home, '/').'/.aws';

        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        $files = [
            'credentials' => [
                'section' => $profile,
                'values' => ['aws_access_key_id' => $accessKeyId, 'aws_secret_access_key' => $secretAccessKey],
            ],
            'config' => [
                'section' => $profile === 'default' ? 'default' : "profile {$profile}",
                'values' => ['region' => $region, 'output' => 'json'],
            ],
        ];

        foreach ($files as $name => $spec) {
            $path = "{$directory}/{$name}";
            $existing = is_file($path) ? (string) file_get_contents($path) : '';

            touch($path);
            chmod($path, 0600);
            file_put_contents($path, self::upsert($existing, $spec['section'], $spec['values']));
        }
    }
}
