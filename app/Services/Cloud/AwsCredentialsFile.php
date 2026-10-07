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

    /** Removes a specific section and its keys from INI-style content. */
    public static function removeSection(string $existing, string $section): string
    {
        $lines = $existing === '' ? [] : (preg_split('/\R/', rtrim($existing, "\r\n")) ?: []);
        $out = [];
        $inTarget = false;

        foreach ($lines as $line) {
            if (preg_match('/^\s*\[(.+)\]\s*$/', $line, $match) === 1) {
                $current = trim($match[1]);
                $inTarget = ($current === $section);
                if ($inTarget) {
                    continue;
                }
            } elseif ($inTarget) {
                continue;
            }

            $out[] = $line;
        }

        $result = implode("\n", $out);
        $result = preg_replace("/\n{3,}/", "\n\n", $result) ?? $result;

        return rtrim($result, "\n")."\n";
    }

    /** Deletes a named profile from ~/.aws/credentials and ~/.aws/config. */
    public static function delete(string $home, string $profile): bool
    {
        $directory = rtrim($home, '/').'/.aws';
        if (! is_dir($directory)) {
            return false;
        }

        $files = [
            'credentials' => [$profile],
            'config' => [$profile === 'default' ? 'default' : "profile {$profile}", $profile],
        ];

        $deletedAny = false;

        foreach ($files as $name => $sections) {
            $path = "{$directory}/{$name}";
            if (! is_file($path)) {
                continue;
            }

            $content = (string) file_get_contents($path);
            $newContent = $content;
            foreach ($sections as $section) {
                $newContent = self::removeSection($newContent, $section);
            }

            if ($newContent !== $content) {
                file_put_contents($path, $newContent);
                $deletedAny = true;
            }
        }

        return $deletedAny;
    }
}
