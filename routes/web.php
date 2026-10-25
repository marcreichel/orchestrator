<?php

use App\Support\Workspaces;
use Illuminate\Support\Facades\Route;

Route::view('/', 'dashboard')->name('home');

/**
 * Every Orca repository with its id, so `orchestrator.repos` can be filled in
 * without digging through the CLI. A dead Orca shows its message in
 * place of the list rather than an error page.
 */
Route::get('/repositories', function () {
    $repositories = [];
    $error = null;

    try {
        $repositories = Workspaces::repositories();
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }

    return view('repositories', compact('repositories', 'error'));
})->name('repositories');
