<?php

namespace CarlJanzell\FilamentFeedback\Concerns;

use CarlJanzell\FilamentFeedback\Enums\Reaction;
use CarlJanzell\FilamentFeedback\Models\FeedbackReaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Posts and replies both take reactions. Load `reactions` before rendering a thread.
 */
trait HasReactions
{
    /**
     * @return MorphMany<FeedbackReaction, $this>
     */
    public function reactions(): MorphMany
    {
        return $this->morphMany(FeedbackReaction::class, 'reactable');
    }

    /**
     * Adds the reaction, or takes it back if this person already gave it.
     */
    public function toggleReaction(Model $user, Reaction $reaction): void
    {
        $existing = $this->reactions()->whereMorphedTo('author', $user)->where('reaction', $reaction)->first();

        if ($existing) {
            $existing->delete();

            return;
        }

        $this->reactions()->make(['reaction' => $reaction])->author()->associate($user)->save();
    }

    /**
     * Every reaction with its count and whether the viewer gave it, in the enum's order.
     *
     * @return list<array{reaction: Reaction, count: int, mine: bool}>
     */
    public function reactionSummary(?Model $viewer): array
    {
        return array_map(fn (Reaction $reaction): array => [
            'reaction' => $reaction,
            'count' => $this->reactions->where('reaction', $reaction)->count(),
            'mine' => $viewer !== null && $this->reactions->contains(fn (FeedbackReaction $given): bool => $given->reaction === $reaction
                && $given->author_type === $viewer->getMorphClass()
                && (string) $given->author_id === (string) $viewer->getKey()),
        ], Reaction::cases());
    }

    public function isWrittenBy(?Model $user): bool
    {
        return $user !== null
            && $this->author_type === $user->getMorphClass()
            && (string) $this->author_id === (string) $user->getKey();
    }
}
