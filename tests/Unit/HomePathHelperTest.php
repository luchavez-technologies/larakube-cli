<?php

test('home_path returns the home directory with no argument', function (): void {
    expect(home_path())->toBe($_SERVER['HOME']);
});

test('home_path joins a relative path onto the home directory', function (): void {
    expect(home_path('.larakube/config.json'))->toBe($_SERVER['HOME'].'/.larakube/config.json');
});

test('home_path falls back to a temp dir when HOME cannot be resolved', function (): void {
    $original = $_SERVER['HOME'];
    unset($_SERVER['HOME']);
    $originalEnv = getenv('HOME');
    putenv('HOME');

    try {
        expect(home_path())->toBe(sys_get_temp_dir());
    } finally {
        $_SERVER['HOME'] = $original;
        if ($originalEnv !== false) {
            putenv("HOME={$originalEnv}");
        }
    }
});
