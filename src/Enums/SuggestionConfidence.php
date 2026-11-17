<?php

namespace CarlJanzell\FilamentFeedback\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * How sure the drafter is. "Send suggested" skips low ones: those need a person to read them.
 */
enum SuggestionConfidence: string implements HasColor, HasLabel
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    public function getLabel(): string
    {
        return match ($this) {
            self::High => 'High confidence',
            self::Medium => 'Medium confidence',
            self::Low => 'Low confidence',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::High => 'success',
            self::Medium => 'info',
            self::Low => 'warning',
        };
    }
}
