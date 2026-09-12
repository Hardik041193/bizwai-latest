<?php

namespace App\Services\Ai\Contracts;

interface AiProvider
{
    /**
     * @param  array<int, array{role: string, content: ?string, tool_call_id?: string, name?: string, tool_calls?: array}>  $messages
     * @param  array<int, array{name: string, description: string, parameters: array}>  $tools  JSON-schema tool defs
     * @return array{content: ?string, tool_calls: array<int, array{id: string, name: string, arguments: array}>}
     */
    public function chat(array $messages, array $tools, string $systemPrompt): array;
}
