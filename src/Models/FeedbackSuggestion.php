<?php

namespace CarlJanzell\FilamentFeedback\Models;

use CarlJanzell\FilamentFeedback\Enums\FeedbackStatus;
use CarlJanzell\FilamentFeedback\Enums\SuggestionConfidence;
use CarlJanzell\FilamentFeedback\Enums\SuggestionOutcome;
use CarlJanzell\FilamentFeedback\Events\SuggestionDecided;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * A drafted reply and status for a post. Nobody but triagers sees it until one of them sends it.
 *
 * @property int $id
 * @property int $feedback_post_id
 * @property string|null $body
 * @property FeedbackStatus|null $status
 * @property string|null $note
 * @property list<string>|null $sources
 * @property SuggestionConfidence $confidence
 * @property string|null $drafted_by
 * @property int|null $for_reply_id
 * @property SuggestionOutcome $outcome
 * @property int|null $final_reply_id
 * @property Carbon|null $decided_at
 * @property Carbon $created_at
 */
class FeedbackSuggestion extends Model
{
    protected $table = 'feedback_suggestions';

    protected $guarded = [];

    protected $attributes = [
        'confidence' => 'medium',
        'outcome' => 'pending',
    ];

    protected $casts = [
        'status' => FeedbackStatus::class,
        'sources' => 'array',
        'confidence' => SuggestionConfidence::class,
        'outcome' => SuggestionOutcome::class,
        'for_reply_id' => 'integer',
        'final_reply_id' => 'integer',
        'decided_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<FeedbackPost, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(FeedbackPost::class, 'feedback_post_id');
    }

    /**
     * @return BelongsTo<FeedbackReply, $this>
     */
    public function finalReply(): BelongsTo
    {
        return $this->belongsTo(FeedbackReply::class, 'final_reply_id');
    }

    public function decidedBy(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<FeedbackSuggestion>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('outcome', SuggestionOutcome::Pending);
    }

    /**
     * Someone wrote in the thread after this was drafted, so it may answer the wrong message.
     */
    public function isStale(): bool
    {
        return $this->post->latestReplyId() !== $this->for_reply_id;
    }

    /**
     * Whether "Send suggested" sends it without a person opening it first.
     */
    public function isSafeToSend(): bool
    {
        return $this->confidence !== SuggestionConfidence::Low && ! $this->isStale();
    }

    public function discard(Model $triager): bool
    {
        if (! $this->decide(SuggestionOutcome::Discarded, $triager)) {
            return false;
        }

        SuggestionDecided::dispatch($this);

        return true;
    }

    /**
     * Records how it ended, unless another triager decided it first (two "Send suggested"
     * presses at once must not reply twice). Returns whether this call won.
     */
    public function decide(SuggestionOutcome $outcome, ?Model $by): bool
    {
        $won = static::query()->whereKey($this->getKey())->pending()->update([
            'outcome' => $outcome->value,
            'decided_by_type' => $by?->getMorphClass(),
            'decided_by_id' => $by?->getKey(),
            'decided_at' => now(),
        ]) === 1;

        if ($won) {
            $this->refresh();
        }

        return $won;
    }
}
