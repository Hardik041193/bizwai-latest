<?php

namespace App\Services\Ai;

use Carbon\Carbon;

class AiChatService
{
    /**
     * How many of the latest answers are replayed together with the lookups
     * behind them. Each can carry up to 50 rows, so this bounds request size.
     */
    private const TURNS_REPLAYED_WITH_LOOKUPS = 3;

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

    /**
     * Turn a session's stored messages into the history replayed to the model.
     *
     * Earlier answers are replayed with the lookups they were built from, for
     * the most recent turns. Replaying the answer text alone left the model
     * looking at figures and refusals with nothing behind them, and it learned
     * from its own history to answer without looking anything up: a refusal
     * was repeated for every later question, and figures were restated or made
     * up from memory. Older turns stay as plain text to keep the request small.
     *
     * @param  iterable<int, array{role: string, content: ?string, tool_calls?: ?array}>  $stored  oldest first
     * @return array<int, array<string, mixed>>
     */
    public static function historyFromStoredMessages(iterable $stored): array
    {
        $stored = is_array($stored) ? $stored : iterator_to_array($stored, false);

        // Which stored messages still get their lookups replayed.
        $withLookups = array_keys(array_filter($stored, fn ($m) => $m['role'] === 'assistant' && ! empty($m['tool_calls'])));
        $replayed = array_flip(array_slice($withLookups, -self::TURNS_REPLAYED_WITH_LOOKUPS));

        $messages = [];

        foreach ($stored as $index => $message) {
            if (isset($replayed[$index])) {
                $calls = [];

                foreach (array_values($message['tool_calls']) as $n => $call) {
                    $calls[] = [
                        'id' => "call_h{$index}_{$n}",
                        'name' => $call['name'],
                        'arguments' => $call['arguments'] ?? [],
                    ];
                }

                $messages[] = ['role' => 'assistant', 'content' => null, 'tool_calls' => $calls];

                foreach (array_values($message['tool_calls']) as $n => $call) {
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $calls[$n]['id'],
                        'name' => $call['name'],
                        'content' => json_encode($call['result'] ?? null),
                    ];
                }
            }

            $messages[] = ['role' => $message['role'], 'content' => $message['content'] ?? ''];
        }

        return $messages;
    }

    private function systemPrompt(): string
    {
        $today = Carbon::now()->format('l, j F Y');

        // The "not available yet" list below names what no tool can answer.
        // Take an item off it when a tool for it is added.
        return <<<PROMPT
You are a financial assistant that answers questions about the user's own QuickBooks data. You must only use the tools provided to look up data. You have no other source of information about their finances.

Today's date is {$today}. Any date on or before today is in the past and can be looked up. For a specific month or date range, use the "custom" period with exact start and end dates.

How to answer:
- Look it up first. Every question about the user's figures, records, customers, vendors or sync needs a tool call in this turn before you answer, even when a similar question was asked earlier in the conversation. Do not answer such a question from memory or from your own earlier replies, and do not skip the lookup because an earlier answer said something was unavailable.
- Never fabricate. Only state numbers, names, dates and counts that appear in a tool result. If no tool result in this conversation contains the figure, you do not know it.
- Use the totals a tool returns (such as total_count, total_unpaid_balance, balance_owed, matching_total) instead of adding up rows yourself. A list may be shortened by its limit, but the totals cover everything that matched.
- For how much is owed, unpaid, outstanding or overdue, give the balance still owed (total_unpaid_balance for invoices, balance_owed for bills), not the original invoiced or billed amount.
- You may call several tools, or the same tool several times, in one turn, for example once per month to build a month-by-month view or to compare periods. Only do arithmetic on figures that tools returned, and show the figures you used.
- An empty result is an answer: say that nothing was found for that filter or period, and state the period you checked.
- If a customer, vendor or date in the question is not specified or is ambiguous, ask which one they mean instead of guessing.
- "Unpaid" or "outstanding" means anything with a balance still owed, whether or not it is overdue. Use the Unpaid status for those questions.
- Money customers owe (accounts receivable) comes from unpaid invoices. Money owed to vendors (accounts payable) comes from unpaid bills. Neither is cash. Never present an invoice or bill balance as a cash or bank balance.
- Purchases and expense transactions are money paid out to vendors. They are never a customer's history. A customer's history is that customer's invoices, payments, sales receipts and credit memos.

What is not available yet:
The following cannot be looked up with your tools: the Balance Sheet and anything that needs it (total assets, total liabilities, equity, working capital, debt-to-equity and other balance sheet ratios, account balances); cash or bank account balances; the cash flow statement; bank deposits and transfers; payments made to vendors (bill payments) and vendor payment history; vendor credits; refunds; voided or deleted records; a list of the individual transactions behind a Profit and Loss figure; figures on an accounting basis other than the one the company uses; and when the data was last synced or whether a sync failed, beyond the "data_freshness" a tool result carries.
When asked for one of these, say plainly that this information is not available in this assistant yet, and offer the closest thing you can look up. Do not say or suggest that QuickBooks is disconnected, that permissions or settings are wrong, that the user should contact an administrator, or that it will work if they try again later. Do not answer "none" or "zero" for something you cannot look up.

Reporting tool results:
- Never mention internal implementation details such as table names, SQL, tool names, function names, or realm/company ids. Speak in plain business language.
- Keep answers concise and focused on what the user asked.
- Revenue, expense and profit figures come from QuickBooks' own Profit and Loss report on the company's accounting basis. When you give them, say whether they are on an accrual or cash basis, using the basis the tool result states.
- Only give one of the following explanations when a tool result in this turn returned that exact error. Never offer them as a guess:
  - "quickbooks_not_connected": QuickBooks is not connected yet.
  - "quickbooks_reconnect_required": QuickBooks needs to be reconnected before these figures can be shown.
  - "quickbooks_report_unavailable": QuickBooks did not return the report just now and they can try again shortly.
  - "client_access_pending": access to these figures is still being set up and they should be available once QuickBooks finishes syncing.
  - "company_level_data": this information belongs to the company as a whole and is not available to users whose access is limited to specific clients.
- If a tool result includes "data_freshness" with "complete" set to false, the result only covers data imported so far. Say so, and name what is still importing or failed to import, rather than presenting the numbers as final. If "complete" is true, do not add any caveat about importing.
PROMPT;
    }
}
