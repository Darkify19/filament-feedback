<?php

namespace CarlJanzell\FilamentFeedback\Tests\Fixtures;

use CarlJanzell\FilamentFeedback\FilamentFeedbackPlugin;
use CarlJanzell\FilamentFeedback\Tests\Fixtures\Pages\LeavePage;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Support\Facades\View;

class TestPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('testing')
            ->path('testing')
            ->databaseNotifications()
            ->pages([LeavePage::class])
            ->plugin(
                FilamentFeedbackPlugin::make()
                    ->modules(['dtr' => ['label' => 'DTR', 'icon' => 'heroicon-o-calendar-days'], 'leave' => 'Leave'])
                    ->moduleFromRoute(fn (?string $route, string $url): ?string => str_contains($url, '/leave') ? 'leave' : null)
                    ->canTriage(fn (): bool => (bool) auth()->user()?->is_admin),
            );
    }

    public function boot(): void
    {
        View::addNamespace('fixtures', __DIR__.'/views');
    }
}
