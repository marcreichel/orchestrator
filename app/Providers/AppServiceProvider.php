<?php

namespace App\Providers;

use App\Support\GitHub;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(GitHub::class, function (): GitHub {
            $token = config('orchestrator.github_token');
            $repos = Config::array('orchestrator.repos');

            if (! is_string($token) || $token === '' || $repos === []) {
                throw new RuntimeException('Set GITHUB_TOKEN in .env and at least one entry in config/orchestrator.php.');
            }

            return new GitHub(
                $token,
                array_map('strval', array_keys($repos)),
                array_map('strval', Config::array('orchestrator.ignore')),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);
    }
}
