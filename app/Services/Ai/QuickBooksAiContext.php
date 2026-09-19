<?php

namespace App\Services\Ai;

use App\Models\User;

/**
 * The single source of QuickBooks authorization scoping used by every AI
 * tool. Resolved entirely server-side from the authenticated user's own
 * token — never accept user_id/realm_id/client filters from request input
 * or from AI tool-call arguments.
 */
final class QuickBooksAiContext
{
    public function __construct(
        public readonly int $userId,
        public readonly ?string $realmId,
        public readonly bool $isAdmin,
        public readonly bool $hasAllClients,
        public readonly array $selectedClientQboIds,
        public readonly array $selectedClientNames,
        // False until the user's client match has resolved. Scoping denies a
        // non-admin everything while it is false, so it defaults to false for
        // any caller that does not know.
        public readonly bool $scopeResolved = false,
    ) {}

    public static function forUser(User $user): self
    {
        $token = $user->quickBooksToken;

        if (! $token) {
            return new self(
                userId: $user->id,
                realmId: null,
                isAdmin: $user->isAdmin(),
                hasAllClients: false,
                selectedClientQboIds: [],
                selectedClientNames: [],
                scopeResolved: false,
            );
        }

        return new self(
            userId: $user->id,
            realmId: $token->realm_id,
            isAdmin: $user->isAdmin(),
            hasAllClients: $token->isAllClientsSelected(),
            selectedClientQboIds: $token->selectedClientQboIds(),
            selectedClientNames: $token->selectedClientNames(),
            scopeResolved: $token->hasCompletedClientSelection(),
        );
    }

    public function hasQuickBooks(): bool
    {
        return $this->realmId !== null;
    }
}
