<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Inertia::share([
            'flash' => fn () => [
                'verify_result' => session('verify_result'),
            ],
        ]);
    }
}
