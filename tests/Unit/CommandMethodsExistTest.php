<?php

/**
 * Every `$this->method()` a command can reach must exist on that command.
 *
 * PHPStan can't check this: Laravel's Command is Macroable, so its magic
 * __call makes any method look valid, and a trait calling a helper that only
 * SOME of its commands have fails at runtime ("Method ... does not exist").
 * This reads each command's own code, its parents' and all its traits', and
 * resolves every call against the real class.
 */
function commandMethodSources(ReflectionClass $class): array
{
    $files = [];
    $file = $class->getFileName();
    if ($file !== false && str_contains($file, DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR)) {
        $files[$file] = true;
    }

    foreach ($class->getTraits() as $trait) {
        $files += commandMethodSources($trait);
    }

    if (($parent = $class->getParentClass()) !== false) {
        $files += commandMethodSources($parent);
    }

    return $files;
}

/** @return array<string, list<string>> method => the files calling it (comments and string contents stripped, guarded calls skipped) */
function commandMethodCalls(string $file): array
{
    static $cache = [];

    return $cache[$file] ??= (function () use ($file): array {
        $source = (string) file_get_contents($file);
        $code = '';
        $calls = '';
        foreach (token_get_all($source) as $token) {
            $text = is_array($token) ? $token[1] : $token;
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= $text;
            // String contents can hold PHP written elsewhere; guards are read from $code.
            if (! is_array($token) || ! in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)) {
                $calls .= $text;
            }
        }

        preg_match_all('/\$this->([A-Za-z_]\w*)\s*\(/', $calls, $m);
        $found = [];
        foreach (array_unique($m[1]) as $method) {
            if (preg_match('/method_exists\(\s*\$this\s*,\s*[\'"]'.$method.'[\'"]\s*\)/', $code) !== 1) {
                $found[$method][] = basename($file);
            }
        }

        return $found;
    })();
}

test('no command calls a method it does not have', function (): void {
    $missing = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Commands')));

    foreach ($files as $file) {
        if (! str_ends_with((string) $file, '.php')) {
            continue;
        }

        $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], substr((string) $file, strlen(app_path()) + 1));
        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);
        if ($reflection->isAbstract()) {
            continue;
        }

        foreach (array_keys(commandMethodSources($reflection)) as $source) {
            foreach (commandMethodCalls($source) as $method => $in) {
                if (! $reflection->hasMethod($method)) {
                    $missing[] = "{$reflection->getShortName()} → {$method}() (called in {$in[0]})";
                }
            }
        }
    }

    sort($missing);

    expect($missing)->toBeEmpty(implode("\n", $missing));
});
