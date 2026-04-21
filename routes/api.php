<?php

use App\Http\Controllers\AuthController;
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
    Route::post('/email/resend', [AuthController::class, 'resendVerification'])
        ->middleware('throttle:6,1');

    // ── QuickBooks ──
    Route::prefix('quickbooks')->name('quickbooks.')->group(function () {
        Route::get('/connect',      [QuickBooksController::class, 'connect'])->name('connect');
        Route::get('/status',       [QuickBooksController::class, 'status'])->name('status');
        Route::get('/summary',      [QuickBooksController::class, 'summary'])->name('summary');
        Route::post('/sync',        [QuickBooksController::class, 'sync'])->name('sync')->middleware('throttle:5,1');
        Route::post('/disconnect',  [QuickBooksController::class, 'disconnect'])->name('disconnect');
        Route::get('/accounts',     [QuickBooksController::class, 'accounts'])->name('accounts');
        Route::get('/invoices',     [QuickBooksController::class, 'invoices'])->name('invoices');
        Route::get('/transactions', [QuickBooksController::class, 'transactions'])->name('transactions');
    });
});
