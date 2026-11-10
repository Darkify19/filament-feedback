<?php

namespace CarlJanzell\FilamentFeedback\Events;

use CarlJanzell\FilamentFeedback\Models\FeedbackReply;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired for both sides of a thread; `$reply->is_from_team` tells them apart.
 */
class ReplyPosted
{
    use Dispatchable;

    public function __construct(public FeedbackReply $reply) {}
}
