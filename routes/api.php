<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\QuickBooksController;
use Illuminate\Support\Facades\Route;

// ── Public auth ──
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/admin/login', [AuthController::class, 'adminLogin']);

/**
 * Email verification — called by the SPA after the user clicks the link.
 *
 * The link in the email goes to:
 *   APP_URL/auth/verify-email/{id}/{hash}?expires=...&signature=...   (SPA route)
 *
 * The SPA page then calls THIS endpoint with the same params to complete verification.
 * The `signed` middleware validates the signature using the named route below.
 */
Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
    ->middleware('signed')
    ->name('verification.verify');

// ── Authenticated ──
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // ── Profile ──
    Route::prefix('profile')->name('profile.')->group(function () {
        Route::get('/',        [ProfileController::class, 'show'])->name('show');
        Route::put('/',        [ProfileController::class, 'update'])->name('update');
        Route::put('/password',[ProfileController::class, 'updatePassword'])->name('password');
        Route::post('/avatar', [ProfileController::class, 'updateAvatar'])->name('avatar');
    });
    Route::post('/email/resend', [AuthController::class, 'resendVerification'])
        ->middleware('throttle:6,1');

    // ── QuickBooks ──
    Route::prefix('quickbooks')->name('quickbooks.')->group(function () {
        // OAuth / management (admin-only enforcement is inside the controller)
        Route::get('/connect',     [QuickBooksController::class, 'connect'])->name('connect');
        Route::post('/disconnect', [QuickBooksController::class, 'disconnect'])->name('disconnect')->middleware('throttle:3,1');
        Route::post('/sync',       [QuickBooksController::class, 'sync'])->name('sync')->middleware('throttle:5,1');

        // Read endpoints — 60 req/min per user
        Route::middleware('throttle:60,1')->group(function () {
            Route::get('/status',       [QuickBooksController::class, 'status'])->name('status');
            Route::get('/summary',      [QuickBooksController::class, 'summary'])->name('summary');
            Route::get('/accounts',     [QuickBooksController::class, 'accounts'])->name('accounts');
            Route::get('/invoices',     [QuickBooksController::class, 'invoices'])->name('invoices');
            Route::get('/transactions', [QuickBooksController::class, 'transactions'])->name('transactions');
        });
    });
});
