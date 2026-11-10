<?php

namespace CarlJanzell\FilamentFeedback\Enums;

use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * A fixed set, not an emoji picker: one tap to acknowledge, and data that stays countable.
 */
enum Reaction: string implements HasIcon, HasLabel
{
    case Thanks = 'thanks';
    case Same = 'same';
    case Seen = 'seen';
    case Fixed = 'fixed';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Thanks => 'Thanks',
            self::Same => 'Same here',
            self::Seen => 'Seen',
            self::Fixed => 'Fixed',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::Thanks => 'heroicon-o-heart',
            self::Same => 'heroicon-o-hand-thumb-up',
            self::Seen => 'heroicon-o-eye',
            self::Fixed => 'heroicon-o-check-circle',
        };
    }
}
