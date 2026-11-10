<?php

namespace CarlJanzell\FilamentFeedback\Models;

use CarlJanzell\FilamentFeedback\Concerns\HasReactions;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $feedback_post_id
 * @property bool $is_from_team
 * @property string $body
 * @property Carbon $created_at
 */
class FeedbackReply extends Model
{
    use HasReactions;

    protected $table = 'feedback_replies';

    protected $guarded = [];

    protected $casts = [
        'is_from_team' => 'boolean',
    ];

    /**
     * @return BelongsTo<FeedbackPost, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(FeedbackPost::class, 'feedback_post_id');
    }

    public function author(): MorphTo
    {
        return $this->morphTo();
    }

    public function authorName(): string
    {
        return $this->author ? Filament::getUserName($this->author) : 'Deleted user';
    }
}
