<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NessusServerController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ScanController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::resource('projects', ProjectController::class);

    Route::get('projects/{project}/scans/{scan}', [ScanController::class, 'show'])
        ->scopeBindings()
        ->name('projects.scans.show');

    Route::resource('nessus-servers', NessusServerController::class)
        ->except('show')
        ->parameters(['nessus-servers' => 'server']);
});

require __DIR__.'/settings.php';
