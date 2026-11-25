<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\AdguardController;
use App\Http\Controllers\DnsController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\IconController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\LinkController;
use App\Http\Controllers\MirrorController;
use App\Http\Controllers\NpmController;
use App\Http\Controllers\SemaphoreController;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\StatusController;
use App\Http\Controllers\NoticeController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WorkbookController;
use App\Http\Controllers\ZoneController;
use App\Http\Controllers\ZoneWizardController;
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

// Cached service icons (see App\Icons\Icons).
Route::get('/icons/{slug}', [IconController::class, 'show'])->where('slug', '[a-z0-9][a-z0-9._-]{0,79}')->name('icons.show');

// Live health for the pages (visibility enforced inside the controller, per section).
Route::get('/status', StatusController::class)->name('status');

// Read screens: visibility is configurable per screen.
Route::get('/services', [ServiceController::class, 'index'])->name('services.index')->middleware('screen:services');
Route::get('/servers', [ServerController::class, 'index'])->name('servers.index')->middleware('screen:servers');
Route::get('/links', [LinkController::class, 'index'])->name('links.index');
Route::get('/admin', [AdminController::class, 'index'])->name('admin.index')->middleware('admin.area');
Route::get('/settings', [SettingsController::class, 'general'])->name('settings.index')->middleware('screen:settings');
Route::get('/settings/access', [SettingsController::class, 'access'])->name('settings.access')->middleware('screen:settings');
Route::get('/users', [UserController::class, 'index'])->name('users.index')->middleware('screen:users');

// Everything that changes data is admin-only, regardless of visibility.
Route::middleware('admin')->group(function () {
    Route::get('/icon-search', [IconController::class, 'search'])->name('icons.search');
    Route::get('/services/{service}/diagnose', [ServiceController::class, 'diagnose'])->name('services.diagnose');
    Route::get('/services/{service}/update', [ServiceController::class, 'runUpdate'])->name('services.run-update');
    Route::post('/services/{service}/diagnose', [ServiceController::class, 'applyDiagnosis'])->name('services.diagnose.apply');
    Route::resource('services', ServiceController::class)->except(['index', 'show']);
    Route::resource('servers', ServerController::class)->except(['index', 'show']);
    Route::resource('links', LinkController::class)->except(['index', 'show']);
    Route::get('/zones/setup', [ZoneWizardController::class, 'show'])->name('zones.wizard');
    Route::post('/zones/setup/{step}', [ZoneWizardController::class, 'save'])->name('zones.wizard.save');
    Route::post('/zones/refresh', [ZoneController::class, 'refresh'])->name('zones.refresh');
    Route::post('/zones/assign', [ZoneController::class, 'assign'])->name('zones.assign');
    Route::post('/zones/{zone}/refresh', [ZoneController::class, 'refresh'])->name('zones.refresh.one');
    Route::resource('zones', ZoneController::class);
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
    Route::get('/workbooks', [WorkbookController::class, 'index'])->name('workbooks.index');
    Route::post('/semaphore/inventory', [SemaphoreController::class, 'inventorySave'])->name('semaphore.inventory.save');
    Route::delete('/semaphore/inventory', [SemaphoreController::class, 'inventoryClear'])->name('semaphore.inventory.clear');
    Route::post('/semaphore/inventory/sync', [SemaphoreController::class, 'inventorySync'])->name('semaphore.inventory.sync');
    Route::get('/semaphore/hosts', [SemaphoreController::class, 'hosts'])->name('semaphore.hosts');
    Route::post('/semaphore/hosts', [SemaphoreController::class, 'hostsApply'])->name('semaphore.hosts.apply');
    Route::post('/notices/{notice}/dismiss', [NoticeController::class, 'dismiss'])->name('notices.dismiss');
    Route::get('/semaphore/run', [SemaphoreController::class, 'runForm'])->name('semaphore.run');
    Route::post('/semaphore/run/confirm', [SemaphoreController::class, 'runConfirm'])->name('semaphore.run.confirm');
    Route::post('/semaphore/run', [SemaphoreController::class, 'runStart'])->name('semaphore.run.start');
    Route::get('/semaphore/tasks/{project}/{task}', [SemaphoreController::class, 'task'])->whereNumber(['project', 'task'])->name('semaphore.task');
    Route::get('/semaphore/tasks/{project}/{task}/status', [SemaphoreController::class, 'taskStatus'])->whereNumber(['project', 'task'])->name('semaphore.task.status');
    Route::post('/semaphore/tasks/{project}/{task}/stop', [SemaphoreController::class, 'taskStop'])->whereNumber(['project', 'task'])->name('semaphore.task.stop');

    Route::get('/npm', [NpmController::class, 'index'])->name('npm.index');
    Route::get('/npm/proxies/{zone}', [NpmController::class, 'proxy'])->whereNumber('zone')->name('npm.proxy');
    Route::get('/npm/proxies/{zone}/edit', [NpmController::class, 'edit'])->whereNumber('zone')->name('npm.proxy.edit');
    Route::post('/npm/connection', [NpmController::class, 'connect'])->name('npm.connect');
    Route::delete('/npm/connection', [NpmController::class, 'disconnect'])->name('npm.disconnect');
    Route::put('/npm/domains', [NpmController::class, 'domains'])->name('npm.domains');
    Route::post('/npm/auto', [NpmController::class, 'auto'])->name('npm.auto');
    Route::get('/npm/wizard', [NpmController::class, 'wizard'])->name('npm.wizard');
    Route::post('/npm/wizard', [NpmController::class, 'wizardApply'])->name('npm.wizard.apply');
    Route::get('/npm/import', [NpmController::class, 'importPreview'])->name('npm.import');
    Route::post('/npm/import', [NpmController::class, 'importApply'])->name('npm.import.apply');
    Route::get('/npm/push', [NpmController::class, 'pushPreview'])->name('npm.push');
    Route::post('/npm/push', [NpmController::class, 'pushApply'])->name('npm.push.apply');
    Route::get('/dns', [DnsController::class, 'index'])->name('dns.index');
    Route::get('/dns/export', [DnsController::class, 'export'])->name('dns.export');
    Route::get('/dns/adguard', [AdguardController::class, 'index'])->name('dns.adguard');
    Route::post('/dns/adguard/connection', [AdguardController::class, 'connect'])->name('dns.adguard.connect');
    Route::delete('/dns/adguard/connection', [AdguardController::class, 'disconnect'])->name('dns.adguard.disconnect');
    Route::get('/dns/adguard/preview', [AdguardController::class, 'preview'])->name('dns.adguard.preview');
    Route::post('/dns/adguard/apply', [AdguardController::class, 'apply'])->name('dns.adguard.apply');
    Route::post('/dns/owner', [DnsController::class, 'owner'])->name('dns.owner');
    Route::post('/dns/propose', [DnsController::class, 'propose'])->name('dns.propose');
    Route::get('/npm/mirrors', [MirrorController::class, 'index'])->name('npm.mirrors');
    Route::delete('/npm/mirrors/{mirror}', [MirrorController::class, 'forget'])->name('npm.mirrors.forget');
    Route::get('/npm/all', [NpmController::class, 'allPreview'])->name('npm.all');
    Route::post('/npm/all', [NpmController::class, 'allApply'])->name('npm.all.apply');

    Route::post('/settings/tokens', [ApiTokenController::class, 'store'])->name('tokens.store');
    Route::delete('/settings/tokens/{token}', [ApiTokenController::class, 'destroy'])->name('tokens.destroy');

    Route::get('/settings/data', [SettingsController::class, 'data'])->name('settings.data');
    Route::get('/settings/mcp', [SettingsController::class, 'mcp'])->name('settings.mcp');
    Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::post('/settings/import', [SettingsController::class, 'import'])->name('settings.import');
    Route::get('/settings/export', [SettingsController::class, 'export'])->name('settings.export');
});
