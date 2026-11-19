<?php

use App\Http\Controllers\McpController;
use App\Http\Middleware\AuthenticateApiToken;
use Illuminate\Support\Facades\Route;

// The MCP endpoint is deliberately outside the `web` group: no cookies, no CSRF, no setup redirect.
// Authentication is a bearer API token created by an admin (Settings).
Route::middleware(['throttle:mcp', AuthenticateApiToken::class])->group(function () {
    Route::post('/mcp', McpController::class)->name('mcp');
    Route::match(['get', 'delete'], '/mcp', [McpController::class, 'unsupported']);
});
