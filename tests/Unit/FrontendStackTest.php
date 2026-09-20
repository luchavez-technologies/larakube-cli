<?php

use App\Enums\FrontendStack;

test('every stack but Livewire runs the local Vite dev server pod', function (): void {
    expect(array_map(fn (FrontendStack $stack) => $stack->requiresNodePod(), FrontendStack::cases()))
        ->toBe([
            true,  // react
            true,  // vue
            true,  // svelte
            false, // livewire
            true,  // vite: Blade/Antlers templates still build assets with Vite
        ]);
});

test('plain Vite is never forwarded to `laravel new` as a starter flag', function (): void {
    // `larakube new` appends getOptionFlag() to `laravel new`, which has no --vite.
    expect(FrontendStack::VITE->getOptionFlag())->toBeEmpty()
        ->and(FrontendStack::REACT->getOptionFlag())->toBe('--react');
});
