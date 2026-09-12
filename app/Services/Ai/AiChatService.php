<?php

namespace App\Services\Ai;

class AiChatService
{
    public function __construct(
        private readonly AiProviderFactory $providerFactory,
        private readonly ToolRegistry $tools,
    ) {}

    /**
     * @param  array<int, array{role: string, content: ?string, tool_call_id?: string, name?: string, tool_calls?: array}>  $conversationMessages  prior turns, oldest first
     * @return array{content: string, tool_calls: array<int, array{name: string, arguments: array, result: array}>}
     */
    public function reply(QuickBooksAiContext $context, array $conversationMessages, string $userMessage): array
    {
        $provider = $this->providerFactory->make();

        $messages = $conversationMessages;
        $messages[] = ['role' => 'user', 'content' => $userMessage];

        $systemPrompt = $this->systemPrompt();
        $toolLog = [];

        $maxIterations = (int) config('ai.max_tool_iterations', 5);

        for ($i = 0; $i < $maxIterations; $i++) {
            $result = $provider->chat($messages, $this->tools->schemas(), $systemPrompt);

            if (empty($result['tool_calls'])) {
                return [
                    'content' => $result['content'] ?? 'I was unable to generate a response. Please try again.',
                    'tool_calls' => $toolLog,
                ];
            }

            // Record the assistant's tool-call turn, then execute each call and
            // feed its result back so the model can respond in natural language.
            $messages[] = [
                'role' => 'assistant',
                'content' => $result['content'],
                'tool_calls' => $result['tool_calls'],
            ];

            foreach ($result['tool_calls'] as $call) {
                $toolResult = $this->tools->execute($call['name'], $call['arguments'], $context);

                $toolLog[] = [
                    'name' => $call['name'],
                    'arguments' => $call['arguments'],
                    'result' => $toolResult,
                ];

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $call['id'],
                    'name' => $call['name'],
                    'content' => json_encode($toolResult),
                ];
            }
        }

        return [
            'content' => 'I gathered some information but was unable to finish forming a complete answer. Please try rephrasing your question.',
            'tool_calls' => $toolLog,
        ];
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You are a financial assistant that answers questions about the user's own QuickBooks data. You must only use the tools provided to look up data — you have no other source of information about their finances.

Rules you must follow:
- Never fabricate financial figures. Only report numbers that came directly from a tool result.
- If a tool returns an error (for example "quickbooks_not_connected"), explain that plainly to the user in plain language rather than guessing at an answer.
- If a customer or date reference in the user's question is ambiguous, ask a clarifying question instead of guessing which one they mean.
- Never mention internal implementation details such as table names, SQL, tool names, function names, or realm/company ids in your response text. Speak in plain business language.
- Keep answers concise and focused on the numbers the user asked about.
PROMPT;
    }
}
