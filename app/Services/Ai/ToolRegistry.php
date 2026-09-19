<?php

namespace App\Services\Ai;

use App\Services\Ai\Tools\CompareFinancialPeriods;
use App\Services\Ai\Tools\Contracts\AiTool;
use App\Services\Ai\Tools\GetBills;
use App\Services\Ai\Tools\GetCompanySummary;
use App\Services\Ai\Tools\GetCreditMemos;
use App\Services\Ai\Tools\GetCustomers;
use App\Services\Ai\Tools\GetExpenses;
use App\Services\Ai\Tools\GetInvoices;
use App\Services\Ai\Tools\GetPayments;
use App\Services\Ai\Tools\GetProfitAndLoss;
use App\Services\Ai\Tools\GetRevenue;
use App\Services\Ai\Tools\GetSalesReceipts;
use App\Services\Ai\Tools\GetTransactions;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class ToolRegistry
{
    /** @var array<string, AiTool> */
    private array $tools = [];

    public function __construct(?array $tools = null)
    {
        $tools ??= [
            new GetCompanySummary,
            new GetProfitAndLoss,
            new GetRevenue,
            new GetExpenses,
            new GetInvoices,
            new GetCustomers,
            new GetTransactions,
            new GetBills,
            new GetPayments,
            new GetSalesReceipts,
            new GetCreditMemos,
            new CompareFinancialPeriods,
        ];

        foreach ($tools as $tool) {
            $this->tools[$tool->name()] = $tool;
        }
    }

    /**
     * @return array<int, array{name: string, description: string, parameters: array}>
     */
    public function schemas(): array
    {
        return array_values(array_map(fn (AiTool $tool) => [
            'name' => $tool->name(),
            'description' => $tool->description(),
            'parameters' => $tool->parameters(),
        ], $this->tools));
    }

    public function execute(string $name, array $arguments, QuickBooksAiContext $context): array
    {
        $tool = $this->tools[$name] ?? null;

        if (! $tool) {
            return ['error' => 'unknown_tool', 'message' => "Unknown tool: {$name}"];
        }

        try {
            return $tool->handle($arguments, $context);
        } catch (InvalidArgumentException $e) {
            return ['error' => 'invalid_arguments', 'message' => $e->getMessage()];
        } catch (Throwable $e) {
            Log::error('AI tool execution failed.', ['tool' => $name, 'error' => $e->getMessage()]);

            return ['error' => 'tool_execution_failed', 'message' => 'The tool could not complete the request.'];
        }
    }
}
