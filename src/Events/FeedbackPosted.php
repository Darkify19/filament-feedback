<?php

namespace CarlJanzell\FilamentFeedback\Events;

use CarlJanzell\FilamentFeedback\Models\FeedbackPost;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Listen for this to tell the people who triage, e.g. a database notification to HR.
 */
class FeedbackPosted
{
    use Dispatchable;

    public function __construct(public FeedbackPost $post) {}
}
