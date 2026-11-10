<?php

namespace CarlJanzell\FilamentFeedback\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Backed by a score so the board can average it: -2 is angry, 2 is love it.
 */
enum FeedbackMood: int implements HasColor, HasIcon, HasLabel
{
    case LoveIt = 2;
    case Good = 1;
    case Unsure = 0;
    case Frustrated = -1;
    case Angry = -2;

    public function getLabel(): ?string
    {
        return match ($this) {
            self::LoveIt => 'Love it',
            self::Good => 'Good',
            self::Unsure => 'Unsure',
            self::Frustrated => 'Frustrated',
            self::Angry => 'Angry',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::LoveIt, self::Good => 'success',
            self::Unsure => 'gray',
            self::Frustrated => 'warning',
            self::Angry => 'danger',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::LoveIt => 'heroicon-o-heart',
            self::Good => 'heroicon-o-face-smile',
            self::Unsure => 'heroicon-o-question-mark-circle',
            self::Frustrated => 'heroicon-o-face-frown',
            self::Angry => 'heroicon-o-fire',
        };
    }
}
