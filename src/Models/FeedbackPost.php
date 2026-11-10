<?php

namespace CarlJanzell\FilamentFeedback\Models;

use CarlJanzell\FilamentFeedback\Concerns\HasReactions;
use CarlJanzell\FilamentFeedback\Enums\FeedbackKind;
use CarlJanzell\FilamentFeedback\Enums\FeedbackMood;
use CarlJanzell\FilamentFeedback\Enums\FeedbackStatus;
use CarlJanzell\FilamentFeedback\Events\ReplyPosted;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $panel
 * @property FeedbackKind $kind
 * @property FeedbackMood|null $mood
 * @property list<string>|null $modules
 * @property string $body
 * @property FeedbackStatus $status
 * @property array<string, mixed>|null $context
 * @property Carbon|null $author_seen_at
 * @property list<array{status: string, at: string}>|null $status_log
 * @property bool|null $fix_confirmed
 * @property Carbon $created_at
 */
class FeedbackPost extends Model
{
    use HasReactions;

    protected $table = 'feedback_posts';

    protected $guarded = [];

    protected $attributes = [
        'status' => 'new',
    ];

    protected $casts = [
        'kind' => FeedbackKind::class,
        'mood' => FeedbackMood::class,
        'status' => FeedbackStatus::class,
        'modules' => 'array',
        'context' => 'array',
        'author_seen_at' => 'datetime',
        'status_log' => 'array',
        'fix_confirmed' => 'boolean',
    ];

    protected static function booted(): void
    {
        // Every status change lands in the log, whoever makes it, so the timeline can't drift.
        static::updating(function (FeedbackPost $post): void {
            if ($post->isDirty('status')) {
                // Done again after a "still broken": ask the author again.
                if ($post->status === FeedbackStatus::Done && ! $post->isDirty('fix_confirmed')) {
                    $post->fix_confirmed = null;
                }

                $post->status_log = [...($post->status_log ?? []), ['status' => $post->status->value, 'at' => now()->toIso8601String()]];
            }
        });
    }

    /**
     * Sent, then each status change, oldest first. A post set back to New reads as reopened.
     *
     * @return list<array{label: string, at: Carbon, status: FeedbackStatus|null}>
     */
    public function timeline(): array
    {
        $steps = [['label' => 'Sent', 'at' => $this->created_at, 'status' => null]];

        foreach ($this->status_log ?? [] as $entry) {
            $status = FeedbackStatus::tryFrom($entry['status']);

            if ($status === null) {
                continue;
            }

            $steps[] = [
                'label' => $status === FeedbackStatus::New ? 'Reopened' : $status->getLabel(),
                'at' => Carbon::parse($entry['at']),
                'status' => $status,
            ];
        }

        return $steps;
    }

    public function author(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<FeedbackReply, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(FeedbackReply::class)->oldest()->oldest('id');
    }

    /**
     * @param  Builder<FeedbackPost>  $query
     */
    public function scopeOwnedBy(Builder $query, Model $user): void
    {
        $query->whereMorphedTo('author', $user);
    }

    /**
     * Posts with a team reply the author hasn't opened the panel to see yet.
     *
     * @param  Builder<FeedbackPost>  $query
     */
    public function scopeWithUnreadReplies(Builder $query): void
    {
        $query->whereHas('replies', fn (Builder $replies) => $replies
            ->where('is_from_team', true)
            ->whereColumn('feedback_replies.created_at', '>', 'feedback_posts.author_seen_at'));
    }

    public function addReply(Model $author, string $body, bool $fromTeam): FeedbackReply
    {
        $reply = $this->replies()->make(['body' => $body, 'is_from_team' => $fromTeam]);
        $reply->author()->associate($author);
        $reply->save();

        ReplyPosted::dispatch($reply);

        return $reply;
    }

    public function authorName(): string
    {
        return $this->author ? Filament::getUserName($this->author) : 'Deleted user';
    }
}
