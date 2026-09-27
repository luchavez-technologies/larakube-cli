<?php

use App\Traits\RemovesPlexTenants;
use Illuminate\Support\Facades\Process;

function redisKeyCounter(): object
{
    return new class
    {
        use RemovesPlexTenants;

        public function count(int $index): int
        {
            return $this->redisKeyCount('larakube-plex', $index);
        }

        protected function plexKubectl(): string
        {
            return 'kubectl';
        }
    };
}

test('an empty index reports zero', function (): void {
    // "0\n", not "0": the string "0" is falsy in PHP, and Laravel's fake
    // result treats a falsy output as no output at all — so a bare '0' here
    // arrives as '' and reads as unparseable rather than as an empty index.
    Process::fake(['*DBSIZE*' => Process::result(output: "0\n"), '*' => Process::result(output: '')]);

    expect(redisKeyCounter()->count(3))->toBe(0);
});

test('a populated index reports its key count', function (): void {
    Process::fake(['*DBSIZE*' => Process::result(output: '13'), '*' => Process::result(output: '')]);

    expect(redisKeyCounter()->count(0))->toBe(13);
});

test('an unreadable index reports -1 rather than zero', function (): void {
    // -1 and 0 must not collapse: 0 means "safe to flush", -1 means "we could
    // not find out", and only the first may authorise a FLUSHDB.
    Process::fake(['*DBSIZE*' => Process::result(output: 'ERR wrong number of arguments'), '*' => Process::result(output: '')]);

    expect(redisKeyCounter()->count(3))->toBe(-1);
});
