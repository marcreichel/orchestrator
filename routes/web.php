<?php

use Illuminate\Support\Facades\Route;
use Polyscope\Laravel\Facades\Polyscope;

Route::view('/', 'dashboard')->name('home');

/**
 * Every Polyscope repository with its id, so `orchestrator.repos` can be filled in
 * without digging through the desktop app. A dead Polyscope shows its message in
 * place of the list rather than an error page.
 */
Route::get('/repositories', function () {
    $repositories = [];
    $error = null;

    try {
        $repositories = Polyscope::repositories();
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }

    return view('repositories', compact('repositories', 'error'));
})->name('repositories');
