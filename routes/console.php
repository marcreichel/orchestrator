<?php

use App\Support\Claude;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * php-fpm runs as a LaunchDaemon, outside the login session, and macOS won't hand it the
 * Keychain token (`security` exits 36, interaction not allowed). So the fetch runs from a
 * LaunchAgent (~/Library/LaunchAgents/orchestrator.usage-refresh.plist, every 2 minutes)
 * and the usage component only reads what it leaves in the cache.
 *
 * A failure keeps the last numbers and only records why: an expired token is routine
 * (it lasts until Claude Code next runs), not worth blanking over.
 */
Artisan::command('usage:refresh', function () {
    try {
        Cache::forever('orchestrator.usage', ['usage' => Claude::usage(), 'error' => null, 'refreshedAt' => now()->toIso8601String()]);
    } catch (Throwable $exception) {
        Cache::forever('orchestrator.usage', ['error' => $exception->getMessage()] + (Cache::get('orchestrator.usage') ?? []));
        $this->error($exception->getMessage());
    }
})->purpose('Fetch the Claude usage into the cache — from the login session, where the Keychain is readable');
