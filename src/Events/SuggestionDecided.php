<?php

namespace CarlJanzell\FilamentFeedback\Events;

use CarlJanzell\FilamentFeedback\Models\FeedbackSuggestion;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A triager sent or discarded a suggested reply; `$suggestion->outcome` says which.
 */
class SuggestionDecided
{
    use Dispatchable;

    public function __construct(public FeedbackSuggestion $suggestion) {}
}
