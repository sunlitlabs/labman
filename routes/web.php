<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\NpmController;
use App\Http\Controllers\SemaphoreController;
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
Route::get('/admin', [AdminController::class, 'index'])->name('admin.index')->middleware('admin.area');
Route::get('/settings', [SettingsController::class, 'general'])->name('settings.index')->middleware('screen:settings');
Route::get('/settings/access', [SettingsController::class, 'access'])->name('settings.access')->middleware('screen:settings');
Route::get('/users', [UserController::class, 'index'])->name('users.index')->middleware('screen:users');

// Everything that changes data is admin-only, regardless of visibility.
Route::middleware('admin')->group(function () {
    Route::resource('services', ServiceController::class)->except(['index', 'show']);
    Route::resource('servers', ServerController::class)->except(['index', 'show']);
    Route::resource('users', UserController::class)->except(['index', 'show', 'destroy']);
    Route::post('/users/{user}/approve', [UserController::class, 'approve'])->name('users.approve');

    Route::get('/inventory', [InventoryController::class, 'index'])->name('inventory.index');
    Route::post('/inventory/connection', [InventoryController::class, 'connect'])->name('inventory.connect');
    Route::delete('/inventory/connection', [InventoryController::class, 'disconnect'])->name('inventory.disconnect');
    Route::get('/inventory/parse-link', [InventoryController::class, 'parseLink'])->name('inventory.parse');
    Route::get('/inventory/compare', [InventoryController::class, 'compare'])->name('inventory.compare');
    Route::post('/inventory/review', [InventoryController::class, 'review'])->name('inventory.review');
    Route::post('/inventory/apply', [InventoryController::class, 'apply'])->name('inventory.apply');

    Route::get('/semaphore', [SemaphoreController::class, 'index'])->name('semaphore.index');
    Route::post('/semaphore/connection', [SemaphoreController::class, 'connect'])->name('semaphore.connect');
    Route::delete('/semaphore/connection', [SemaphoreController::class, 'disconnect'])->name('semaphore.disconnect');
    Route::get('/semaphore/run', [SemaphoreController::class, 'runForm'])->name('semaphore.run');
    Route::post('/semaphore/run/confirm', [SemaphoreController::class, 'runConfirm'])->name('semaphore.run.confirm');
    Route::post('/semaphore/run', [SemaphoreController::class, 'runStart'])->name('semaphore.run.start');
    Route::get('/semaphore/tasks/{project}/{task}', [SemaphoreController::class, 'task'])->whereNumber(['project', 'task'])->name('semaphore.task');
    Route::get('/semaphore/tasks/{project}/{task}/status', [SemaphoreController::class, 'taskStatus'])->whereNumber(['project', 'task'])->name('semaphore.task.status');
    Route::post('/semaphore/tasks/{project}/{task}/stop', [SemaphoreController::class, 'taskStop'])->whereNumber(['project', 'task'])->name('semaphore.task.stop');

    Route::get('/npm', [NpmController::class, 'index'])->name('npm.index');
    Route::post('/npm/connection', [NpmController::class, 'connect'])->name('npm.connect');
    Route::delete('/npm/connection', [NpmController::class, 'disconnect'])->name('npm.disconnect');
    Route::get('/npm/import', [NpmController::class, 'importPreview'])->name('npm.import');
    Route::post('/npm/import', [NpmController::class, 'importApply'])->name('npm.import.apply');
    Route::get('/npm/push', [NpmController::class, 'pushPreview'])->name('npm.push');
    Route::post('/npm/push', [NpmController::class, 'pushApply'])->name('npm.push.apply');

    Route::post('/settings/tokens', [ApiTokenController::class, 'store'])->name('tokens.store');
    Route::delete('/settings/tokens/{token}', [ApiTokenController::class, 'destroy'])->name('tokens.destroy');

    Route::get('/settings/data', [SettingsController::class, 'data'])->name('settings.data');
    Route::get('/settings/mcp', [SettingsController::class, 'mcp'])->name('settings.mcp');
    Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::post('/settings/import', [SettingsController::class, 'import'])->name('settings.import');
    Route::get('/settings/export', [SettingsController::class, 'export'])->name('settings.export');
});
