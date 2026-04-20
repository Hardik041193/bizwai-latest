<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        /**
         * Override the verification email link to point to the SPA frontend.
         *
         * Standard SPA flow:
         *   1. Email link  →  APP_URL/auth/verify-email/{id}/{hash}?expires=...&signature=...
         *   2. SPA reads params  →  calls POST /api/email/verify/{id}/{hash}?expires=...&signature=...
         *   3. Backend validates signed request  →  marks email_verified_at
         *   4. SPA redirects to login with ?verified=1
         */
        VerifyEmail::createUrlUsing(function (object $notifiable): string {
            $frontendBase = rtrim(config('app.url'), '/');

            // Build the backend signed URL (used as the validation source)
            $signedBackendUrl = URL::temporarySignedRoute(
                'verification.verify',
                Carbon::now()->addMinutes(60),
                [
                    'id'   => $notifiable->getKey(),
                    'hash' => sha1($notifiable->getEmailForVerification()),
                ]
            );

            // Extract query params from signed backend URL
            $parsed    = parse_url($signedBackendUrl);
            $id        = $notifiable->getKey();
            $hash      = sha1($notifiable->getEmailForVerification());
            parse_str($parsed['query'] ?? '', $queryParams);

            // Build SPA frontend URL: /auth/verify-email/{id}/{hash}?expires=...&signature=...
            return $frontendBase
                . '/auth/verify-email/' . $id . '/' . $hash
                . '?' . http_build_query([
                    'expires'   => $queryParams['expires']   ?? '',
                    'signature' => $queryParams['signature'] ?? '',
                ]);
        });
    }
}
