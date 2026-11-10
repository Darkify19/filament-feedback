<?php

namespace CarlJanzell\FilamentFeedback\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum FeedbackKind: string implements HasColor, HasIcon, HasLabel
{
    case Feedback = 'feedback';
    case Question = 'question';
    case Problem = 'problem';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Feedback => 'Share feedback',
            self::Question => 'Ask a question',
            self::Problem => 'Report a problem',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Feedback => 'primary',
            self::Question => 'info',
            self::Problem => 'danger',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::Feedback => 'heroicon-o-chat-bubble-left-ellipsis',
            self::Question => 'heroicon-o-question-mark-circle',
            self::Problem => 'heroicon-o-bug-ant',
        };
    }

    /**
     * Form state to a case: Filament 4+ holds enum options as enums, Filament 3 as their values.
     */
    public static function fromState(mixed $state): ?self
    {
        return $state instanceof self ? $state : self::tryFrom((string) $state);
    }

    /**
     * The short form for badges, where "Share feedback" reads as a button.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Feedback => 'Feedback',
            self::Question => 'Question',
            self::Problem => 'Problem',
        };
    }
}
