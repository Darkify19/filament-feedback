<?php

namespace CarlJanzell\FilamentFeedback\Actions;

use CarlJanzell\FilamentFeedback\Enums\FeedbackStatus;
use CarlJanzell\FilamentFeedback\FilamentFeedbackPlugin;
use CarlJanzell\FilamentFeedback\Models\FeedbackPost;
use CarlJanzell\FilamentFeedback\Models\FeedbackReply;
use CarlJanzell\FilamentFeedback\Support\Compat;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Throwable;

/**
 * A team reply and a status, the way the inbox's Save does it: the author is notified of the
 * reply, and the status change lands in the timeline. An empty body only sets the status.
 */
class RespondToPost
{
    public function execute(FeedbackPost $post, Model $triager, ?string $body, FeedbackStatus $status): ?FeedbackReply
    {
        $body = trim((string) $body);
        $reply = $body !== '' ? $post->addReply($triager, $body, fromTeam: true) : null;

        if ($reply) {
            $this->notifyAuthor($post, $reply);
        }

        $post->update(['status' => $status]);

        return $reply;
    }

    protected function notifyAuthor(FeedbackPost $post, FeedbackReply $reply): void
    {
        $author = $post->author;

        if (! $author || ! method_exists($author, 'notify')) {
            return;
        }

        // The thread on My feedback, on the panel the post was written on.
        $url = FilamentFeedbackPlugin::threadUrl($post->panel, $post->getKey());

        // The reply is saved either way; a failed notification must not undo that for the triager.
        try {
            $plugin = FilamentFeedbackPlugin::get();

            if ($custom = $plugin->getNotifyAuthorUsing()) {
                $plugin->evaluate($custom, ['post' => $post, 'reply' => $reply, 'author' => $author, 'url' => $url]);

                return;
            }

            Notification::make()
                ->title('The team replied to your '.Str::lower($post->kind->shortLabel()))
                ->body(Str::limit(Str::squish($reply->body), 140))
                ->icon('heroicon-o-chat-bubble-left-right')
                ->actions($url ? [Compat::notificationAction('read')->label('Read the reply')->url($url)] : [])
                ->sendToDatabase($author);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
