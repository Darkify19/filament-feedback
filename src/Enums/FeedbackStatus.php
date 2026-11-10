<?php

namespace CarlJanzell\FilamentFeedback\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum FeedbackStatus: string implements HasColor, HasIcon, HasLabel
{
    case New = 'new';
    case Seen = 'seen';
    case Planned = 'planned';
    case Done = 'done';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::New => 'New',
            self::Seen => 'Seen',
            self::Planned => 'Planned',
            self::Done => 'Done',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::New => 'warning',
            self::Seen => 'gray',
            self::Planned => 'info',
            self::Done => 'success',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::New => 'heroicon-o-sparkles',
            self::Seen => 'heroicon-o-eye',
            self::Planned => 'heroicon-o-calendar-days',
            self::Done => 'heroicon-o-check-circle',
        };
    }
}
