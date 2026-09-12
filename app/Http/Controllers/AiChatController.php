<?php

namespace App\Http\Controllers;

use App\Models\AiChatMessage;
use App\Models\AiChatSession;
use App\Models\AiQueryLog;
use App\Models\User;
use App\Services\Ai\AiChatService;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\QuickBooksAiContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AiChatController extends Controller
{
    public function __construct(private readonly AiChatService $aiChat) {}

    /**
     * List the authenticated user's chat sessions, most recently updated first.
     */
    public function index(Request $request): JsonResponse
    {
        $sessions = AiChatSession::where('user_id', $request->user()->id)
            ->with(['messages' => fn ($q) => $q->latest('created_at')->limit(1)])
            ->orderByDesc('updated_at')
            ->paginate($request->integer('per_page', 20));

        return response()->json($sessions);
    }

    /**
     * Create a new, empty chat session for the authenticated user.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
        ]);

        $session = AiChatSession::create([
            'user_id' => $request->user()->id,
            'title' => $validated['title'] ?? null,
        ]);

        return response()->json($session, 201);
    }

    /**
     * Show a session with its full message history.
     */
    public function show(Request $request, AiChatSession $session): JsonResponse
    {
        abort_unless($session->user_id === $request->user()->id, 404);

        $session->load('messages');

        return response()->json($session);
    }

    /**
     * Send a message in a session and get the AI assistant's reply.
     */
    public function sendMessage(Request $request, AiChatSession $session): JsonResponse
    {
        abort_unless($session->user_id === $request->user()->id, 404);

        $validated = $request->validate([
            'message' => 'required|string|max:4000',
        ]);

        $user = $request->user();
        $context = QuickBooksAiContext::forUser($user);

        // Only replay plain role/content turns as history — the stored
        // tool_calls are an audit-log shape ({name, arguments, result}), not
        // the provider wire shape (which needs a call `id`), and each turn's
        // AiChatService::reply() runs its own fresh tool loop anyway.
        $priorMessages = $session->messages()
            ->get()
            ->map(fn (AiChatMessage $m) => [
                'role' => $m->role,
                'content' => $m->content ?? '',
            ])
            ->all();

        $userMessage = AiChatMessage::create([
            'session_id' => $session->id,
            'role' => 'user',
            'content' => $validated['message'],
        ]);

        // Title the conversation from its first question, ChatGPT-style,
        // instead of leaving it as a permanent "New conversation" label.
        if ($session->title === null) {
            $session->update(['title' => Str::limit($validated['message'], 60)]);
        }

        $startedAt = microtime(true);
        $error = null;
        $response = null;

        try {
            $result = $this->aiChat->reply($context, $priorMessages, $validated['message']);

            $assistantMessage = AiChatMessage::create([
                'session_id' => $session->id,
                'role' => 'assistant',
                'content' => $result['content'],
                'tool_calls' => $result['tool_calls'],
            ]);

            $response = $result['content'];
            $session->touch();

            $this->logQuery($user, $context, $validated['message'], $result['tool_calls'], $response, $startedAt, null);

            // Piggyback the (possibly just-set) title so the frontend can update
            // the sidebar label without a separate round trip.
            $assistantMessage->setAttribute('session_title', $session->title);

            return response()->json($assistantMessage);
        } catch (AiProviderException $e) {
            $error = $e->getMessage();
            $this->logQuery($user, $context, $validated['message'], null, null, $startedAt, $error);

            return response()->json(['message' => $error], 503);
        } catch (\Throwable $e) {
            Log::error('AI chat sendMessage failed.', [
                'session_id' => $session->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            $error = 'Something went wrong answering that question. Please try again.';
            $this->logQuery($user, $context, $validated['message'], null, null, $startedAt, substr($e->getMessage(), 0, 255));

            return response()->json(['message' => $error], 500);
        }
    }

    /**
     * Delete a session (and its messages, via cascade).
     */
    public function destroy(Request $request, AiChatSession $session): JsonResponse
    {
        abort_unless($session->user_id === $request->user()->id, 404);

        $session->delete();

        return response()->json(['message' => 'Conversation deleted successfully.']);
    }

    private function logQuery(
        User $user,
        QuickBooksAiContext $context,
        string $question,
        ?array $toolCalls,
        ?string $response,
        float $startedAt,
        ?string $error
    ): void {
        AiQueryLog::create([
            'user_id' => $user->id,
            'realm_id' => $context->realmId,
            'question' => $question,
            'provider' => (string) config('ai.provider'),
            'tool_calls' => $toolCalls,
            'response' => $response,
            'execution_time_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'error' => $error,
        ]);
    }
}
