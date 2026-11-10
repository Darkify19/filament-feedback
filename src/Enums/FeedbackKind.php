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
    case Developers = 'developers';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Feedback => 'Share feedback',
            self::Question => 'Ask a question',
            self::Problem => 'Report a problem',
            self::Developers => 'For the developers',
        };
    }

    /**
     * One line under the label in the sheet, so people pick the right box.
     */
    public function getDescription(): string
    {
        return match ($this) {
            self::Feedback => 'What works, what doesn\'t',
            self::Question => 'How do I…? Where is…?',
            self::Problem => 'Something broke or looks wrong',
            self::Developers => 'Ideas and notes for the dev team',
        };
    }

    /**
     * Only feedback is scored, so only feedback needs a mood and a module.
     */
    public function isScored(): bool
    {
        return $this === self::Feedback;
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Feedback => 'primary',
            self::Question => 'info',
            self::Problem => 'danger',
            self::Developers => 'gray',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::Feedback => 'heroicon-o-chat-bubble-left-ellipsis',
            self::Question => 'heroicon-o-question-mark-circle',
            self::Problem => 'heroicon-o-bug-ant',
            self::Developers => 'heroicon-o-code-bracket',
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
            self::Developers => 'For developers',
        };
    }
}
