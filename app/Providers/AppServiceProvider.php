<?php

namespace App\Providers;

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // GET /up answers 500 when the database is unreachable, so an uptime monitor notices more than a running PHP.
        Event::listen(DiagnosingHealth::class, fn () => DB::select('select 1'));

        // Behind a TLS-terminating proxy the app sees plain HTTP; follow APP_URL so links, redirects and assets stay on HTTPS.
        if ($this->app->isProduction() && str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }
    }
}
