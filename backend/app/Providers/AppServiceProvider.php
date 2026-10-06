<?php

namespace App\Providers;

use App\Support\PhoneNormalizer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PhoneNormalizer::class, function (): PhoneNormalizer {
            return new PhoneNormalizer((string) config('smartpbx.phone_default_country_code', '98'));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Password::defaults(fn () => Password::min(12));

        RateLimiter::for('asterisk-ingest', function (Request $request) {
            $tokenId = $request->user()?->currentAccessToken()?->id;

            return Limit::perMinute(600)->by('asterisk-ingest:'.($tokenId ?? $request->ip()));
        });
    }
}
