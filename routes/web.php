<?php

use App\Http\Controllers\AppController;
use App\Http\Controllers\QuickBooksController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

// QuickBooks OAuth callback — must be declared before the SPA catch-all.
// Intuit redirects the browser here with ?code=&realmId=&state=
// This route requires the 'auth' (session) middleware so we know which user
// is completing the OAuth flow.
Route::get('/quickbooks/callback', [QuickBooksController::class, 'callback'])
    ->name('quickbooks.callback');

// SPA catch-all — must remain last.
Route::get('/{any}', [AppController::class, 'index'])->where('any', '.*');
