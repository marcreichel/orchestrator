<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class Claude
{
    /**
     * The subscription's session (5h) and weekly (7d) limits, as Claude Code's `/usage` shows them.
     *
     * The endpoint is undocumented — it's what Claude Code itself calls. The token is the one
     * Claude Code keeps in the Keychain and is only ever read: refreshing it here would rotate
     * the refresh token out from under Claude Code and log it out. Once it expires the call
     * fails until Claude Code next runs and writes a fresh one.
     *
     * @return array<string, array{percent: int, resetsAt: ?string}>
     */
    public static function usage(): array
    {
        $body = Http::withToken(self::token())
            ->withHeaders(['anthropic-beta' => 'oauth-2025-04-20'])
            ->timeout(10)
            ->get('https://api.anthropic.com/api/oauth/usage')
            ->throw()
            ->json();

        return collect(['5h' => 'five_hour', '7d' => 'seven_day'])
            ->map(fn (string $key): array => [
                'percent' => (int) round((float) data_get($body, "{$key}.utilization", 0)),
                // A string, not a Carbon: Livewire doesn't rehydrate one nested in an array.
                // ISO so the browser can count down to it.
                'resetsAt' => ($resetsAt = data_get($body, "{$key}.resets_at"))
                    ? Carbon::parse($resetsAt)->toIso8601String()
                    : null,
            ])
            ->all();
    }

    private static function token(): string
    {
        $process = Process::run(['security', 'find-generic-password', '-s', 'Claude Code-credentials', '-w']);

        $token = data_get(json_decode($process->output(), true), 'claudeAiOauth.accessToken');

        if ($process->failed() || ! is_string($token)) {
            throw new RuntimeException('No Claude Code login in the Keychain');
        }

        return $token;
    }
}
