<?php

use App\Services\Devbox\DevBoxMarker;

test('a server with no marker is not a dev box', function (): void {
    @unlink(DevBoxMarker::path());

    expect(DevBoxMarker::isHere())->toBeFalse()
        ->and(DevBoxMarker::name())->toBeNull();
});

test('the marker names the dev box it is on', function (): void {
    @mkdir(dirname(DevBoxMarker::path()), 0700, true);
    file_put_contents(DevBoxMarker::path(), "test-dev-box\n");

    expect(DevBoxMarker::isHere())->toBeTrue()
        ->and(DevBoxMarker::name())->toBe('test-dev-box');
});

test('the script that leaves the marker quotes the name and can be run again', function (): void {
    $script = DevBoxMarker::writeScript("my box'; rm -rf /");

    expect($script)->toContain('mkdir -p "$HOME/.larakube"')
        ->toContain("'my box'\\''; rm -rf /'")
        ->toContain('> "$HOME/.larakube/devbox"');
});

test('on a dev box the viewing hint says where to see the app and how to get a link that stays', function (): void {
    $hint = implode(' ', DevBoxMarker::viewingHint());

    expect($hint)->toContain('open only on the box')
        ->toContain('larakube share')
        ->toContain('Share preview')
        ->toContain('larakube share:domain')
        ->toContain('your own Cloudflare domain');
});

test('the service links point a dev box at share instead of leaving only box-local addresses', function (): void {
    $source = (string) file_get_contents(base_path('app/Traits/LaraKubeOutput.php'));

    expect($source)->toContain("if (\$environment === 'local' && DevBoxMarker::isHere())");
});
