<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to accounts whose users.role is exactly "user".
 *
 * The financial dashboard pages are a user-portal feature; admins have their
 * own area and are refused here even with a valid token, so the API cannot be
 * reached by hand around the frontend route guard.
 */
class EnsureUserRole
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->role !== 'user') {
            return response()->json(['message' => 'This page is only available to user accounts.'], 403);
        }

        return $next($request);
    }
}
