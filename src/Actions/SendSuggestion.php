<?php

namespace CarlJanzell\FilamentFeedback\Actions;

use CarlJanzell\FilamentFeedback\Enums\FeedbackStatus;
use CarlJanzell\FilamentFeedback\Enums\SuggestionOutcome;
use CarlJanzell\FilamentFeedback\Events\SuggestionDecided;
use CarlJanzell\FilamentFeedback\Models\FeedbackReply;
use CarlJanzell\FilamentFeedback\Models\FeedbackSuggestion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Sends a pending suggestion as `$triager`, through the same path as a reply typed in the inbox.
 * `$editedBody` is what the reply box held on Save; null sends the draft as it is. Either way the
 * suggestion records whether it went out untouched, for the drafter to learn from.
 */
class SendSuggestion
{
    public function __construct(protected RespondToPost $respond) {}

    /**
     * @throws LogicException when another triager already sent or discarded it
     */
    public function execute(FeedbackSuggestion $suggestion, Model $triager, ?string $editedBody = null, ?FeedbackStatus $status = null): ?FeedbackReply
    {
        $drafted = trim((string) $suggestion->body);
        $body = $editedBody === null ? $drafted : trim($editedBody);
        $post = $suggestion->post;
        $status ??= $suggestion->status ?? $post->status;

        $reply = DB::transaction(function () use ($suggestion, $triager, $drafted, $body, $post, $status): ?FeedbackReply {
            $outcome = $body === $drafted ? SuggestionOutcome::SentAsIs : SuggestionOutcome::SentEdited;

            if (! $suggestion->decide($outcome, $triager)) {
                throw new LogicException("Suggestion {$suggestion->getKey()} was already sent or discarded.");
            }

            $reply = $this->respond->execute($post, $triager, $body, $status);
            $suggestion->update(['final_reply_id' => $reply?->getKey()]);

            return $reply;
        });

        SuggestionDecided::dispatch($suggestion);

        return $reply;
    }
}
