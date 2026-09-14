<?php

use App\Http\Controllers\Api\NessusServerController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ScanController;
use App\Http\Controllers\Api\ScanImportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| JSON API (/api/...)
|--------------------------------------------------------------------------
|
| Registered in bootstrap/app.php with the "web" middleware group, so these
| endpoints use the logged-in session and CSRF protection. Vue calls them
| for actions such as "Test Connection"; the browser never talks to Nessus.
|
*/

Route::middleware('throttle:api')->group(function () {
    Route::controller(NessusServerController::class)
        ->prefix('nessus/servers')
        ->name('nessus.servers.')
        ->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/', 'store')->name('store');
            Route::get('{server}', 'show')->name('show');
            Route::put('{server}', 'update')->name('update');
            Route::delete('{server}', 'destroy')->name('destroy');
            Route::post('{server}/test', 'test')->middleware('throttle:nessus-test')->name('test');
        });

    Route::apiResource('projects', ProjectController::class);

    Route::controller(ProjectController::class)
        ->prefix('projects/{project}')
        ->name('projects.')
        ->group(function () {
            Route::get('dashboard', 'dashboard')->name('dashboard');
            Route::get('scans', 'scans')->name('scans');
            Route::get('assets', 'assets')->name('assets');
            Route::get('vulnerabilities', 'vulnerabilities')->name('vulnerabilities');
            Route::get('reports', 'reports')->name('reports');
        });

    // Importing scans that were run in the Nessus UI (read-only Nessus API).
    Route::controller(ScanImportController::class)
        ->prefix('projects/{project}')
        ->name('projects.')
        ->scopeBindings()
        ->group(function () {
            Route::get('nessus-scans', 'index')->name('nessus-scans');
            Route::post('scans/import', 'store')->name('scans.import');
            Route::post('scans/{scan}/sync', 'sync')->name('scans.sync');
        });

    Route::get('projects/{project}/scans/{scan}/plugins/{plugin}', [ScanController::class, 'plugin'])
        ->scopeBindings()
        ->whereNumber('plugin')
        ->name('projects.scans.plugin');

    Route::post('projects/{project}/scans/{scan}/reports', [ReportController::class, 'store'])
        ->scopeBindings()
        ->name('projects.scans.reports.store');
});
