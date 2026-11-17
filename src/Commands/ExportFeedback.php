<?php

namespace CarlJanzell\FilamentFeedback\Commands;

use CarlJanzell\FilamentFeedback\Enums\FeedbackStatus;
use CarlJanzell\FilamentFeedback\Enums\SuggestionOutcome;
use CarlJanzell\FilamentFeedback\Models\FeedbackPost;
use CarlJanzell\FilamentFeedback\Models\FeedbackReply;
use CarlJanzell\FilamentFeedback\Models\FeedbackSuggestion;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Posts and their threads as JSON, for a tool that drafts suggested replies (`feedback:suggest`
 * reads its answer). Author names are left out: the drafter looks an author up by id only when
 * it needs their records.
 */
class ExportFeedback extends Command
{
    protected $signature = 'feedback:export
        {--open : Only posts waiting on the team: not done, no team reply yet or the author spoke last, and no pending suggestion}
        {--post=* : Only these post ids}
        {--since= : Only posts written or replied to since this date}';

    protected $description = 'Print feedback posts and their threads as JSON, for drafting suggested replies';

    public function handle(): int
    {
        $since = $this->option('since') ? Carbon::parse($this->option('since')) : null;

        $posts = FeedbackPost::query()
            ->with(['replies', 'pendingSuggestion'])
            ->when($this->option('post'), fn (Builder $query, array $ids) => $query->whereKey($ids))
            ->when($since, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('created_at', '>=', $since)
                ->orWhereHas('replies', fn (Builder $replies) => $replies->where('created_at', '>=', $since))))
            ->when($this->option('open'), fn (Builder $query) => $query
                ->where('status', '!=', FeedbackStatus::Done)
                ->whereDoesntHave('suggestions', fn (Builder $suggestions) => $suggestions->pending()))
            ->oldest('id')
            ->get()
            ->when($this->option('open'), fn ($posts) => $posts->filter(fn (FeedbackPost $post): bool => $this->waitingOnTeam($post)));

        $outcomes = FeedbackSuggestion::query()
            ->whereIn('outcome', [SuggestionOutcome::SentAsIs, SuggestionOutcome::SentEdited, SuggestionOutcome::Discarded])
            ->with('finalReply')
            ->latest('decided_at')
            ->limit(30)
            ->get();

        $this->line(json_encode([
            'exported_at' => now()->toIso8601String(),
            'posts' => $posts->map(fn (FeedbackPost $post): array => [
                'id' => $post->getKey(),
                'panel' => $post->panel,
                'kind' => $post->kind->value,
                'mood' => $post->mood?->value,
                'status' => $post->status->value,
                'modules' => $post->modules ?? [],
                'body' => $post->body,
                'created_at' => $post->created_at->toIso8601String(),
                'context' => $post->context ?? (object) [],
                'author' => ['type' => $post->author_type, 'id' => $post->author_id],
                'replies' => $post->replies->map(fn (FeedbackReply $reply): array => [
                    'id' => $reply->getKey(),
                    'is_from_team' => $reply->is_from_team,
                    'body' => $reply->body,
                    'created_at' => $reply->created_at->toIso8601String(),
                    'unsent' => $reply->isUnsent(),
                ])->values()->all(),
                'pending_suggestion_id' => $post->pendingSuggestion?->getKey(),
            ])->values()->all(),
            'recent_outcomes' => $outcomes->map(fn (FeedbackSuggestion $suggestion): array => [
                'post_id' => $suggestion->feedback_post_id,
                'draft' => $suggestion->body,
                'final' => $suggestion->finalReply?->body,
                'outcome' => $suggestion->outcome->value,
            ])->all(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * No team reply yet, or the author wrote after the team's last one. Unsent messages don't count.
     */
    protected function waitingOnTeam(FeedbackPost $post): bool
    {
        $last = $post->replies->reject(fn (FeedbackReply $reply): bool => $reply->isUnsent())->last();

        return ! ($last?->is_from_team ?? false);
    }
}
