<?php

namespace CarlJanzell\FilamentFeedback\Support;

use Filament\Actions\Action;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Schemas\Schema;
use Filament\Tables\Actions\Action as TableAction;

/**
 * The few places Filament 3 and Filament 4/5 disagree on a class name.
 */
final class Compat
{
    /**
     * A table row action: `Tables\Actions\Action` on 3, the unified `Actions\Action` on 4 and 5.
     */
    public static function tableAction(string $name): Action|TableAction
    {
        return class_exists(TableAction::class)
            ? TableAction::make($name)
            : Action::make($name);
    }

    /**
     * A notification button: `Notifications\Actions\Action` on 3, `Actions\Action` on 4 and 5.
     */
    public static function notificationAction(string $name): Action|NotificationAction
    {
        return class_exists(NotificationAction::class)
            ? NotificationAction::make($name)
            : Action::make($name);
    }

    /**
     * A panel colour as a CSS value. Filament 3 stores "r, g, b" triplets, 4 and 5 store full colours.
     */
    public static function color(string $name, int $shade = 500): string
    {
        return class_exists(Schema::class)
            ? "var(--{$name}-{$shade})"
            : "rgb(var(--{$name}-{$shade}))";
    }
}
