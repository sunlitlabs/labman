<?php

use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\NpmController;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\StatusController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// First-run wizard (the RedirectToSetup middleware gates it).
Route::get('/setup', [SetupController::class, 'show'])->name('setup');
Route::post('/setup', [SetupController::class, 'store'])->name('setup.store');

Route::get('/', HomeController::class)->name('home');

// Authentication
Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'store'])->name('login.store');
Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
Route::get('/register', [RegisterController::class, 'show'])->name('register');
Route::post('/register', [RegisterController::class, 'store'])->name('register.store')->middleware('throttle:10,1');

// Live health for the pages (visibility enforced inside the controller, per section).
Route::get('/status', StatusController::class)->name('status');

// Read screens: visibility is configurable per screen.
Route::get('/services', [ServiceController::class, 'index'])->name('services.index')->middleware('screen:services');
Route::get('/servers', [ServerController::class, 'index'])->name('servers.index')->middleware('screen:servers');
Route::get('/settings', [SettingsController::class, 'show'])->name('settings.index')->middleware('screen:settings');
Route::get('/users', [UserController::class, 'index'])->name('users.index')->middleware('screen:users');

// Everything that changes data is admin-only, regardless of visibility.
Route::middleware('admin')->group(function () {
    Route::resource('services', ServiceController::class)->except(['index', 'show']);
    Route::resource('servers', ServerController::class)->except(['index', 'show']);
    Route::resource('users', UserController::class)->except(['index', 'show', 'destroy']);
    Route::post('/users/{user}/approve', [UserController::class, 'approve'])->name('users.approve');

    Route::get('/npm', [NpmController::class, 'index'])->name('npm.index');
    Route::post('/npm/connection', [NpmController::class, 'connect'])->name('npm.connect');
    Route::delete('/npm/connection', [NpmController::class, 'disconnect'])->name('npm.disconnect');
    Route::get('/npm/import', [NpmController::class, 'importPreview'])->name('npm.import');
    Route::post('/npm/import', [NpmController::class, 'importApply'])->name('npm.import.apply');
    Route::get('/npm/push', [NpmController::class, 'pushPreview'])->name('npm.push');
    Route::post('/npm/push', [NpmController::class, 'pushApply'])->name('npm.push.apply');

    Route::post('/settings/tokens', [ApiTokenController::class, 'store'])->name('tokens.store');
    Route::delete('/settings/tokens/{token}', [ApiTokenController::class, 'destroy'])->name('tokens.destroy');

    Route::put('/settings',[SettingsController::class, 'update'])->name('settings.update');
    Route::post('/settings/import', [SettingsController::class, 'import'])->name('settings.import');
    Route::get('/settings/export', [SettingsController::class, 'export'])->name('settings.export');
});
