<?php

namespace App\Services\Devbox;

/**
 * A small file that says "this server is a dev box". `devbox:create` leaves it in the login's home, so
 * the CLI running there knows its own `.kube` names open only on the box and can say how to see an app
 * from another computer.
 */
final class DevBoxMarker
{
    public static function path(): string
    {
        return home_path('.larakube/devbox');
    }

    public static function isHere(): bool
    {
        return is_file(self::path());
    }

    /** The name the box was given when it was made, or null when this is not a dev box. */
    public static function name(): ?string
    {
        if (! self::isHere()) {
            return null;
        }

        $name = trim((string) file_get_contents(self::path()));

        return $name !== '' ? $name : null;
    }

    /** The shell that leaves the marker, to run on the box as its login. Safe to run again. */
    public static function writeScript(string $name): string
    {
        return 'mkdir -p "$HOME/.larakube" && printf \'%s\\n\' '.escapeshellarg($name).' > "$HOME/.larakube/devbox"';
    }

    /**
     * What `up` and `about` tell a person on a dev box about the addresses they just listed.
     *
     * @return list<string>
     */
    public static function viewingHint(): array
    {
        return [
            'This is a dev box, so these addresses open only on the box itself.',
            'To see the app from your computer: larakube share (a temporary public link), or Share preview in LaraKube Desktop.',
            'For a link that stays, run larakube share --token <Cloudflare tunnel token> once; it asks for the public address.',
        ];
    }
}
