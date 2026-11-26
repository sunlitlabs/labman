<?php

use App\Http\Controllers\McpController;
use App\Http\Middleware\AuthenticateApiToken;
use Illuminate\Support\Facades\Route;

// The MCP endpoint is deliberately outside the `web` group: no cookies, no CSRF, no setup redirect.
// Authentication is a bearer API token created by an admin (Settings).
// A node accepts pushes from the main LabMan here (it has its own bearer token and answers 404 unless in node mode).
Route::post('/node/push', \App\Http\Controllers\NodePushController::class)->middleware('throttle:60,1')->name('node.push');

Route::middleware(['throttle:mcp', \App\Http\Middleware\NodeModeOnly::class, AuthenticateApiToken::class])->group(function () {
    Route::post('/mcp', McpController::class)->name('mcp');
    Route::match(['get', 'delete'], '/mcp', [McpController::class, 'unsupported']);
});
