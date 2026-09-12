<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Exceptions\AiProviderException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class OpenAiProvider implements AiProvider
{
    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $model,
        private readonly string $baseUrl,
        private readonly int $timeout = 30,
    ) {
        if (empty($this->apiKey)) {
            throw new AiProviderException('The AI provider is not configured.');
        }
    }

    public function chat(array $messages, array $tools, string $systemPrompt): array
    {
        $payload = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ...array_map([$this, 'toWireMessage'], $messages),
            ],
        ];

        if (! empty($tools)) {
            $payload['tools'] = array_map(fn (array $tool) => [
                'type' => 'function',
                'function' => [
                    'name' => $tool['name'],
                    'description' => $tool['description'],
                    'parameters' => $tool['parameters'],
                ],
            ], $tools);
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout($this->timeout)
                ->acceptJson()
                ->post(rtrim($this->baseUrl, '/').'/chat/completions', $payload);
        } catch (Throwable $e) {
            Log::error('OpenAI request failed.', ['error' => $e->getMessage()]);
            throw new AiProviderException('The AI service is temporarily unavailable. Please try again.');
        }

        if ($response->failed()) {
            Log::error('OpenAI returned an error response.', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new AiProviderException('The AI service is temporarily unavailable. Please try again.');
        }

        $message = $response->json('choices.0.message');

        if (! is_array($message)) {
            Log::error('OpenAI returned a malformed response.', ['body' => $response->body()]);
            throw new AiProviderException('The AI service returned an unexpected response. Please try again.');
        }

        $toolCalls = [];

        foreach ($message['tool_calls'] ?? [] as $call) {
            $arguments = json_decode($call['function']['arguments'] ?? '{}', true);

            $toolCalls[] = [
                'id' => $call['id'],
                'name' => $call['function']['name'],
                'arguments' => is_array($arguments) ? $arguments : [],
            ];
        }

        return [
            'content' => $message['content'] ?? null,
            'tool_calls' => $toolCalls,
        ];
    }

    /**
     * Translate a neutral internal message into OpenAI's wire format.
     */
    private function toWireMessage(array $message): array
    {
        if ($message['role'] === 'assistant' && ! empty($message['tool_calls'])) {
            return [
                'role' => 'assistant',
                'content' => $message['content'],
                'tool_calls' => array_map(fn (array $call) => [
                    'id' => $call['id'],
                    'type' => 'function',
                    'function' => [
                        'name' => $call['name'],
                        'arguments' => json_encode($call['arguments']),
                    ],
                ], $message['tool_calls']),
            ];
        }

        if ($message['role'] === 'tool') {
            return [
                'role' => 'tool',
                'tool_call_id' => $message['tool_call_id'],
                'name' => $message['name'] ?? '',
                'content' => $message['content'],
            ];
        }

        return [
            'role' => $message['role'],
            'content' => $message['content'],
        ];
    }
}
