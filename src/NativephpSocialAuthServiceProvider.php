<?php

namespace Codingwithrk\NativephpSocialAuth;

use Illuminate\Support\ServiceProvider;

class NativephpSocialAuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/nativephp-social-auth.php', 'nativephp-social-auth');

        $this->app->singleton(NativephpSocialAuth::class, function () {
            return new NativephpSocialAuth();
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/nativephp-social-auth.php' => config_path('nativephp-social-auth.php'),
            ], 'nativephp-social-auth-config');
        }
    }
}
