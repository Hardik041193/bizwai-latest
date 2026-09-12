<?php

namespace App\Services\Ai\Tools\Contracts;

use App\Services\Ai\QuickBooksAiContext;

interface AiTool
{
    public function name(): string;

    public function description(): string;

    /**
     * JSON schema: ['type' => 'object', 'properties' => [...], 'required' => [...]]
     */
    public function parameters(): array;

    /**
     * @return array<string, mixed> structured, JSON-serializable result
     */
    public function handle(array $arguments, QuickBooksAiContext $context): array;
}
