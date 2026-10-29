<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

beforeEach(function () {
    config()->set('app.timezone', 'UTC');
});

function fakeKeychain(?string $token = 'secret'): void
{
    Process::fake([
        "*'security' 'find-generic-password'*" => $token === null
            ? Process::result(errorOutput: 'The specified item could not be found in the keychain.', exitCode: 44)
            : Process::result(json_encode(['claudeAiOauth' => ['accessToken' => $token]])),
    ]);
    Process::preventStrayProcesses();
}

function fakeUsage(float $session = 14.0, float $weekly = 62.0, int $status = 200): void
{
    Http::fake(['api.anthropic.com/api/oauth/usage' => Http::response([
        'five_hour' => ['utilization' => $session, 'resets_at' => '2026-10-28T03:59:59.929493+00:00'],
        'seven_day' => ['utilization' => $weekly, 'resets_at' => '2026-10-28T17:59:59.929514+00:00'],
    ], $status)]);
}

it('shows the session and weekly usage with the Keychain token', function () {
    fakeKeychain();
    fakeUsage();

    loaded('usage')
        ->assertSet('usage.5h.percent', 14)
        ->assertSet('usage.7d.percent', 62)
        ->assertSee('5h resets Wed, Oct 28 3:59 AM')
        ->assertSee('7d resets Wed, Oct 28 5:59 PM')
        // The countdown is the browser's, from the exact reset time.
        ->assertSeeHtml('x-text="left(\'2026-10-28T03:59:59+00:00\')"');

    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer secret')
        && $request->hasHeader('anthropic-beta', 'oauth-2025-04-20'));
    expect(data_get(Cache::get('orchestrator.usage'), 'usage.5h.percent'))->toBe(14);
});

it('colours a window amber from 75% and rose from 90%', function () {
    fakeKeychain();
    fakeUsage(session: 75, weekly: 90);

    loaded('usage')
        ->assertSeeHtml('<span class="text-amber">75%</span>')
        ->assertSeeHtml('<span class="text-rose">90%</span>')
        ->assertSeeHtml('bg-current text-amber" style="width: 75%"')
        ->assertSeeHtml('bg-current text-rose" style="width: 90%"');
});

it('caps the bar at full width when a window runs over', function () {
    fakeKeychain();
    fakeUsage(session: 104);

    loaded('usage')
        ->assertSee('104%')
        ->assertSeeHtml('style="width: 100%"');
});

it('keeps the last numbers and marks them stale when the token has expired', function () {
    Cache::forever('orchestrator.usage', ['usage' => ['5h' => ['percent' => 40, 'resetsAt' => null], '7d' => ['percent' => 10, 'resetsAt' => null]]]);
    fakeKeychain();
    fakeUsage(status: 401);

    loaded('usage')
        ->assertSet('usage.5h.percent', 40)
        ->assertSee('40%')
        ->assertSee('Stale:')
        ->assertSeeHtml('opacity-50');
});

it('shows a dash when there is no Claude Code login and nothing cached', function () {
    fakeKeychain(token: null);
    Http::fake();

    loaded('usage')
        ->assertSet('usage', [])
        ->assertSet('error', 'No Claude Code login in the Keychain')
        ->assertSee('—');

    Http::assertNothingSent();
});

it('renders the cached numbers before fetching', function () {
    Cache::forever('orchestrator.usage', ['usage' => ['5h' => ['percent' => 33, 'resetsAt' => null]]]);

    Livewire::test('usage')->assertSee('33%');
});

it('forecasts where a window lands at its reset and flags one on pace to run out', function () {
    $this->travelTo('2026-10-28T01:59:59+00:00');
    fakeKeychain();
    // 5h: 2h of 5h gone at 60% → on pace for 150%. 7d: ~6d of 7d gone at 10% → 12%.
    Http::fake(['api.anthropic.com/api/oauth/usage' => Http::response([
        'five_hour' => ['utilization' => 60, 'resets_at' => '2026-10-28T04:59:59+00:00'],
        'seven_day' => ['utilization' => 10, 'resets_at' => '2026-10-29T01:59:59+00:00'],
    ])]);

    loaded('usage')
        ->assertSee('→150%')
        ->assertDontSee('→12%')
        ->assertSee('on pace for 12%');
});

it('skips the forecast in the first tenth of a window', function () {
    $this->travelTo('2026-10-28T00:10:00+00:00');
    fakeKeychain();
    Http::fake(['api.anthropic.com/api/oauth/usage' => Http::response([
        'five_hour' => ['utilization' => 50, 'resets_at' => '2026-10-28T05:00:00+00:00'],
        'seven_day' => ['utilization' => 1, 'resets_at' => '2026-11-04T00:10:00+00:00'],
    ])]);

    loaded('usage')->assertDontSee('on pace');
});
