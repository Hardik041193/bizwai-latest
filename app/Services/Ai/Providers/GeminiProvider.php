<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Exceptions\AiProviderException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class GeminiProvider implements AiProvider
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
            'systemInstruction' => [
                'parts' => [['text' => $systemPrompt]],
            ],
            'contents' => array_map([$this, 'toWireContent'], $messages),
        ];

        if (! empty($tools)) {
            $payload['tools'] = [[
                'functionDeclarations' => array_map(fn (array $tool) => [
                    'name' => $tool['name'],
                    'description' => $tool['description'],
                    'parameters' => $tool['parameters'],
                ], $tools),
            ]];
        }

        $url = rtrim($this->baseUrl, '/')."/models/{$this->model}:generateContent?key=".urlencode($this->apiKey);

        try {
            $response = Http::timeout($this->timeout)
                ->acceptJson()
                ->post($url, $payload);
        } catch (Throwable $e) {
            Log::error('Gemini request failed.', ['error' => $e->getMessage()]);
            throw new AiProviderException('The AI service is temporarily unavailable. Please try again.');
        }

        if ($response->failed()) {
            Log::error('Gemini returned an error response.', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new AiProviderException('The AI service is temporarily unavailable. Please try again.');
        }

        $parts = $response->json('candidates.0.content.parts');

        if (! is_array($parts)) {
            Log::error('Gemini returned a malformed response.', ['body' => $response->body()]);
            throw new AiProviderException('The AI service returned an unexpected response. Please try again.');
        }

        $content = null;
        $toolCalls = [];

        foreach ($parts as $part) {
            if (isset($part['text'])) {
                $content = ($content ?? '').$part['text'];
            }

            if (isset($part['functionCall'])) {
                $toolCalls[] = [
                    'id' => 'call_'.Str::random(12),
                    'name' => $part['functionCall']['name'],
                    'arguments' => is_array($part['functionCall']['args'] ?? null) ? $part['functionCall']['args'] : [],
                ];
            }
        }

        return [
            'content' => $content,
            'tool_calls' => $toolCalls,
        ];
    }

    /**
     * Translate a neutral internal message into a Gemini "contents" entry.
     */
    private function toWireContent(array $message): array
    {
        if ($message['role'] === 'tool') {
            $response = json_decode((string) $message['content'], true);

            if (! is_array($response)) {
                $response = ['result' => $message['content']];
            }

            return [
                'role' => 'function',
                'parts' => [[
                    'functionResponse' => [
                        'name' => $message['name'] ?? '',
                        // An empty PHP array json-encodes as `[]`, which Gemini's
                        // struct-typed `response` field rejects — force `{}` instead.
                        'response' => empty($response) ? (object) [] : $response,
                    ],
                ]],
            ];
        }

        if ($message['role'] === 'assistant' && ! empty($message['tool_calls'])) {
            $parts = [];

            if (! empty($message['content'])) {
                $parts[] = ['text' => $message['content']];
            }

            foreach ($message['tool_calls'] as $call) {
                $parts[] = [
                    'functionCall' => [
                        'name' => $call['name'],
                        // Same empty-array-vs-empty-object issue as functionResponse above.
                        'args' => empty($call['arguments']) ? (object) [] : $call['arguments'],
                    ],
                ];
            }

            return ['role' => 'model', 'parts' => $parts];
        }

        return [
            'role' => $message['role'] === 'assistant' ? 'model' : 'user',
            'parts' => [['text' => (string) $message['content']]],
        ];
    }
}
