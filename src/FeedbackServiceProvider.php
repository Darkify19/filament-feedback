<?php

namespace CarlJanzell\FilamentFeedback;

use CarlJanzell\FilamentFeedback\Livewire\FeedbackLauncher;
use Filament\Support\Assets\Css;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class FeedbackServiceProvider extends ServiceProvider
{
    public const PACKAGE = 'carljanzell/filament-feedback';

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'filament-feedback');

        // Plain CSS, published by `php artisan filament:assets`: the host's Tailwind build
        // never scans this package, so its views can't rely on utility classes.
        FilamentAsset::register([
            Css::make('filament-feedback', __DIR__.'/../resources/css/feedback.css'),
            // Motion: imported by the launcher only when the viewer allows it.
            Js::make('anime', __DIR__.'/../resources/js/vendor/anime.esm.min.js')->loadedOnRequest(),
            Js::make('motion', __DIR__.'/../resources/js/motion.js')->loadedOnRequest(),
        ], static::PACKAGE);

        // Livewire 3 can't resolve a package component by name on an update request
        // unless it is registered; Livewire 4 doesn't need it but accepts it.
        Livewire::component('filament-feedback-launcher', FeedbackLauncher::class);
    }
}
