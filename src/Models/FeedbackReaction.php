<?php

namespace CarlJanzell\FilamentFeedback\Models;

use CarlJanzell\FilamentFeedback\Enums\Reaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property Reaction $reaction
 */
class FeedbackReaction extends Model
{
    protected $table = 'feedback_reactions';

    protected $guarded = [];

    protected $casts = [
        'reaction' => Reaction::class,
    ];

    public function reactable(): MorphTo
    {
        return $this->morphTo();
    }

    public function author(): MorphTo
    {
        return $this->morphTo();
    }
}
