<?php

use App\Commands\CloneCommand;
use App\Traits\ClonesRepositories;
use Spatie\TemporaryDirectory\TemporaryDirectory;

// ── URL resolution ────────────────────────────────────────────────────────────

test('resolveRepoUrl passes full HTTPS URLs through unchanged', function (): void {
    $trait = new class
    {
        use ClonesRepositories;

        public function resolve(string $r): string
        {
            return $this->resolveRepoUrl($r);
        }
    };

    expect($trait->resolve('https://github.com/laravel/laravel.git'))
        ->toBe('https://github.com/laravel/laravel.git');
});

test('resolveRepoUrl passes full SSH URLs through unchanged', function (): void {
    $trait = new class
    {
        use ClonesRepositories;

        public function resolve(string $r): string
        {
            return $this->resolveRepoUrl($r);
        }
    };

    expect($trait->resolve('git@github.com:laravel/laravel.git'))
        ->toBe('git@github.com:laravel/laravel.git');
});

test('resolveRepoUrl expands user/repo shorthand to GitHub HTTPS', function (): void {
    $trait = new class
    {
        use ClonesRepositories;

        public function resolve(string $r): string
        {
            return $this->resolveRepoUrl($r);
        }
    };

    expect($trait->resolve('laravel/laravel'))
        ->toBe('https://github.com/laravel/laravel.git');
});

// ── Directory name derivation ─────────────────────────────────────────────────

test('deriveDirectoryName strips .git suffix', function (): void {
    $trait = new class
    {
        use ClonesRepositories;

        public function derive(string $u): string
        {
            return $this->deriveDirectoryName($u);
        }
    };

    expect($trait->derive('https://github.com/laravel/laravel.git'))->toBe('laravel')
        ->and($trait->derive('git@github.com:user/my-app.git'))->toBe('my-app')
        ->and($trait->derive('https://github.com/user/no-suffix'))->toBe('no-suffix');
});

// ── .env bootstrapping ────────────────────────────────────────────────────────

test('bootstrapDotEnv throws when .env.example is missing', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $dir = $temporaryDirectory->path();

    $trait = new class
    {
        use ClonesRepositories;

        public function bootstrap(string $d): string
        {
            return $this->bootstrapDotEnv($d);
        }
    };

    expect(fn () => $trait->bootstrap($dir))->toThrow(RuntimeException::class);

    $temporaryDirectory->delete();
});

test('bootstrapDotEnv copies .env.example to .env', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $dir = $temporaryDirectory->path();
    file_put_contents($dir.'/.env.example', "APP_NAME=Laravel\nAPP_KEY=\n");

    $trait = new class
    {
        use ClonesRepositories;

        public function bootstrap(string $d): string
        {
            return $this->bootstrapDotEnv($d);
        }
    };

    $result = $trait->bootstrap($dir);

    expect($result)->toBe('copied')
        ->and(file_exists($dir.'/.env'))->toBeTrue();

    $temporaryDirectory->delete();
});

test('bootstrapDotEnv returns exists when .env already present', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $dir = $temporaryDirectory->path();
    file_put_contents($dir.'/.env.example', "APP_NAME=Laravel\n");
    file_put_contents($dir.'/.env', "APP_NAME=Existing\n");

    $trait = new class
    {
        use ClonesRepositories;

        public function bootstrap(string $d): string
        {
            return $this->bootstrapDotEnv($d);
        }
    };

    $result = $trait->bootstrap($dir);

    expect($result)->toBe('exists')
        ->and(file_get_contents($dir.'/.env'))->toBe("APP_NAME=Existing\n");

    $temporaryDirectory->delete();
});

// ── .env patching ─────────────────────────────────────────────────────────────

test('patchDotEnv replaces existing keys and appends new ones', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $dir = $temporaryDirectory->path();
    file_put_contents($dir.'/.env', "APP_URL=http://localhost\nAPP_KEY=base64:abc\n");

    $trait = new class
    {
        use ClonesRepositories;

        public function patch(string $d, array $v): void
        {
            $this->patchDotEnv($d, $v);
        }
    };

    $trait->patch($dir, [
        'APP_URL' => 'https://myapp.kube',
        'ASSET_URL' => 'https://myapp.kube',
    ]);

    $content = file_get_contents($dir.'/.env');

    expect($content)->toContain('APP_URL=https://myapp.kube')
        ->toContain('ASSET_URL=https://myapp.kube')
        ->toContain('APP_KEY=base64:abc')
        ->not->toContain('APP_URL=http://localhost');

    $temporaryDirectory->delete();
});

// ── Command structure ─────────────────────────────────────────────────────────

test('clone command has repo argument and expected options', function (): void {
    $cmd = new CloneCommand;
    $def = $cmd->getDefinition();

    expect($def->hasArgument('repo'))->toBeTrue()
        ->and($def->hasOption('directory'))->toBeTrue()
        ->and($def->hasOption('branch'))->toBeTrue()
        ->and($def->hasOption('provider'))->toBeTrue()
        ->and($def->hasOption('no-install'))->toBeTrue();
});

// ── Provider flag (Phase 2) ───────────────────────────────────────────────────

test('resolveRepoUrl expands user/repo to GitLab HTTPS when provider is gitlab', function (): void {
    $trait = new class
    {
        use ClonesRepositories;

        public function resolve(string $r, string $p = 'github'): string
        {
            return $this->resolveRepoUrl($r, $p);
        }
    };

    expect($trait->resolve('myorg/myapp', 'gitlab'))
        ->toBe('https://gitlab.com/myorg/myapp.git');
});

test('resolveRepoUrl expands user/repo to Bitbucket HTTPS when provider is bitbucket', function (): void {
    $trait = new class
    {
        use ClonesRepositories;

        public function resolve(string $r, string $p = 'github'): string
        {
            return $this->resolveRepoUrl($r, $p);
        }
    };

    expect($trait->resolve('myorg/myapp', 'bitbucket'))
        ->toBe('https://bitbucket.org/myorg/myapp.git');
});

test('resolveRepoUrl ignores provider for full URLs', function (): void {
    $trait = new class
    {
        use ClonesRepositories;

        public function resolve(string $r, string $p = 'github'): string
        {
            return $this->resolveRepoUrl($r, $p);
        }
    };

    $fullUrl = 'https://gitlab.com/myorg/myapp.git';
    expect($trait->resolve($fullUrl, 'github'))->toBe($fullUrl);
});
