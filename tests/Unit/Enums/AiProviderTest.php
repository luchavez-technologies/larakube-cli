<?php

use App\Enums\AiProvider;

test('ai provider has correct labels', function () {
    expect(AiProvider::OPENAI->getLabel())->toBe('OpenAI')
        ->and(AiProvider::ANTHROPIC->getLabel())->toBe('Anthropic')
        ->and(AiProvider::GEMINI->getLabel())->toBe('Google Gemini')
        ->and(AiProvider::AZURE->getLabel())->toBe('Azure OpenAI')
        ->and(AiProvider::GROQ->getLabel())->toBe('Groq')
        ->and(AiProvider::XAI->getLabel())->toBe('xAI (Grok)')
        ->and(AiProvider::DEEPSEEK->getLabel())->toBe('DeepSeek')
        ->and(AiProvider::MISTRAL->getLabel())->toBe('Mistral AI')
        ->and(AiProvider::OLLAMA->getLabel())->toBe('Ollama (Local)');
});

test('ai provider has correct command options', function () {
    $options = AiProvider::getCommandOptions();
    expect($options)->toContain('openai', 'anthropic', 'gemini', 'ollama');
});

test('ai provider has correct command option arrays', function () {
    $options = AiProvider::getCommandOptionArrays();
    expect($options)->toBeArray()
        ->and($options[0])->toHaveKeys(['name', 'description'])
        ->and($options[0]['name'])->toBe('openai');
});
