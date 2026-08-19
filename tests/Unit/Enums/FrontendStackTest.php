<?php

use App\Enums\FrontendStack;

test('frontend stack has correct labels', function (): void {
    expect(FrontendStack::REACT->getLabel())->toBe('React')
        ->and(FrontendStack::VUE->getLabel())->toBe('Vue')
        ->and(FrontendStack::SVELTE->getLabel())->toBe('Svelte')
        ->and(FrontendStack::LIVEWIRE->getLabel())->toBe('Livewire');
});

test('frontend stack pod name is always node', function (): void {
    expect(FrontendStack::REACT->getPodName())->toBe('node')
        ->and(FrontendStack::LIVEWIRE->getPodName())->toBe('node');
});

test('livewire does not require node pod', function (): void {
    expect(FrontendStack::LIVEWIRE->requiresNodePod())->toBeFalse()
        ->and(FrontendStack::REACT->requiresNodePod())->toBeTrue();
});

test('frontend stack echo package', function (): void {
    expect(FrontendStack::REACT->echoPackage())->toBe('@laravel/echo-react')
        ->and(FrontendStack::VUE->echoPackage())->toBe('@laravel/echo-vue')
        ->and(FrontendStack::SVELTE->echoPackage())->toBeNull();
});

test('frontend stack select options are valid', function (): void {
    $options = FrontendStack::getSelectOptions();
    expect($options)->toBeArray()
        ->and($options)->toHaveKey('react', 'React');
});
