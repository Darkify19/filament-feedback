<?php

namespace CarlJanzell\FilamentFeedback\Tests\Fixtures\Pages;

use Filament\Pages\Page;

/**
 * A host page the fixture's moduleFromRoute() maps to "leave": its navigation icon becomes
 * the Leave module's icon, and pressing Feedback here pre-selects Leave.
 */
class LeavePage extends Page
{
    protected static ?string $slug = 'leave';

    public function getView(): string
    {
        return 'fixtures::leave';
    }

    public function getTitle(): string
    {
        return 'Leave';
    }

    public static function getNavigationLabel(): string
    {
        return 'Leave';
    }

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-sun';
    }
}
