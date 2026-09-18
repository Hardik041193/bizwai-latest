<?php

use App\Http\Controllers\AiChatController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\QuickBooksController;
use App\Http\Controllers\UserController; // ← ADD THIS
use Illuminate\Support\Facades\Route;

// ── Public auth ──
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/admin/login', [AuthController::class, 'adminLogin']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');

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

    // ── Admin Dashboard Stats ── ← ADD THIS
    Route::get('/admin/dashboard/stats', [DashboardController::class, 'stats'])
        ->name('admin.dashboard.stats');

    // ── Admin Dashboard Charts ──
    Route::get('/admin/dashboard/charts', [DashboardController::class, 'charts'])
        ->name('admin.dashboard.charts');

    // ── Admin: Users CRUD ──
    Route::prefix('admin/users')->name('admin.users.')->middleware('throttle:60,1')->group(function () {
        Route::get('/', [UserController::class, 'index'])->name('index');
        Route::post('/', [UserController::class, 'store'])->name('store');
        Route::get('/{user}', [UserController::class, 'show'])->name('show');
        Route::put('/{user}', [UserController::class, 'update'])->name('update');
        Route::delete('/{user}', [UserController::class, 'destroy'])->name('destroy');

        Route::patch('/{user}/approve', [UserController::class, 'approve'])->name('approve');
        Route::patch('/{user}/revoke', [UserController::class, 'revoke'])->name('revoke');
    });

    // ── Admin: Contact Us Listing ──
    Route::prefix('admin/contact')->name('admin.contact.')->middleware('throttle:60,1')->group(function () {
        Route::get('/', [ContactController::class, 'adminIndex'])->name('index');
        Route::patch('/{id}/read', [ContactController::class, 'markRead'])->name('markRead');
        Route::delete('/{id}', [ContactController::class, 'destroy'])->name('destroy');
    });

    Route::get('/contact-us', [ContactController::class, 'index'])->name('contact.index');
    Route::post('/contact-us', [ContactController::class, 'store'])->name('contact.store');

    // ── Profile ──
    Route::prefix('profile')->name('profile.')->group(function () {
        Route::get('/', [ProfileController::class, 'show'])->name('show');
        Route::put('/', [ProfileController::class, 'update'])->name('update');
        Route::put('/password', [ProfileController::class, 'updatePassword'])->name('password');
        Route::post('/avatar', [ProfileController::class, 'updateAvatar'])->name('avatar');
    });
    Route::post('/email/resend', [AuthController::class, 'resendVerification'])
        ->middleware('throttle:6,1');

    // ── QuickBooks ──
    Route::prefix('quickbooks')->name('quickbooks.')->group(function () {
        // OAuth / management (admin-only enforcement is inside the controller)
        Route::get('/connect', [QuickBooksController::class, 'connect'])
            ->name('connect')
            ->withoutMiddleware([\Illuminate\Routing\Middleware\ThrottleRequests::class.':api'])
            ->middleware('throttle:quickbooks-connect');

        Route::post('/disconnect', [QuickBooksController::class, 'disconnect'])->name('disconnect')->middleware('throttle:10,1');
        Route::post('/sync', [QuickBooksController::class, 'sync'])->name('sync')->middleware('throttle:10,1');

        Route::get('/selection/clients', [QuickBooksController::class, 'selectionClients'])->name('selection.clients');
        Route::post('/selection/client', [QuickBooksController::class, 'saveClientSelection'])->name('selection.save');
        Route::delete('/selection/client', [QuickBooksController::class, 'clearClientSelection'])->name('selection.clear');

        // Read endpoints — covered by the global api limiter (no extra per-route throttle).
        Route::get('/status', [QuickBooksController::class, 'status'])->name('status');
        Route::get('/summary', [QuickBooksController::class, 'summary'])->name('summary');
        Route::get('/accounts', [QuickBooksController::class, 'accounts'])->name('accounts');
        Route::get('/customers', [QuickBooksController::class, 'customers'])->name('customers');
        Route::get('/invoices', [QuickBooksController::class, 'invoices'])->name('invoices');
        Route::get('/transactions', [QuickBooksController::class, 'transactions'])->name('transactions');
    });

    // ── AI Chat ──
    Route::prefix('ai-chat')->name('ai-chat.')->group(function () {
        Route::get('/sessions', [AiChatController::class, 'index'])->name('sessions.index');
        Route::post('/sessions', [AiChatController::class, 'store'])->name('sessions.store');
        Route::get('/sessions/{session}', [AiChatController::class, 'show'])->name('sessions.show');
        Route::post('/sessions/{session}/messages', [AiChatController::class, 'sendMessage'])->middleware('throttle:20,1')->name('sessions.messages');
        Route::delete('/sessions/{session}', [AiChatController::class, 'destroy'])->name('sessions.destroy');
    });
});
