<?php

namespace App\Services\Ai;

use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\Providers\GeminiProvider;
use App\Services\Ai\Providers\OpenAiProvider;

class AiProviderFactory
{
    public function make(): AiProvider
    {
        $provider = config('ai.provider');
        $timeout = (int) config('ai.request_timeout', 30);

        return match ($provider) {
            'gemini' => $this->makeGemini($timeout),
            'openai' => new OpenAiProvider(
                config('ai.openai.api_key'),
                config('ai.openai.model'),
                config('ai.openai.base_url'),
                $timeout,
            ),
            default => throw new AiProviderException("Unknown AI provider: {$provider}"),
        };
    }

    private function makeGemini(int $timeout): GeminiProvider
    {
        if (! config('ai.gemini.enabled')) {
            throw new AiProviderException('The Gemini provider is disabled. Set AI_GEMINI_ENABLED=true or switch AI_PROVIDER to openai.');
        }

        return new GeminiProvider(
            config('ai.gemini.api_key'),
            config('ai.gemini.model'),
            config('ai.gemini.base_url'),
            $timeout,
        );
    }
}
