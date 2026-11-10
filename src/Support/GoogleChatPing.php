<?php

namespace CarlJanzell\FilamentFeedback\Support;

use CarlJanzell\FilamentFeedback\FilamentFeedbackPlugin;
use CarlJanzell\FilamentFeedback\Models\FeedbackPost;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Posts one line about a new post to a Google Chat space webhook. Outbound only, so it works
 * from a server Google can't reach, and it needs no Cloud project or Workspace approval.
 */
final class GoogleChatPing
{
    public static function send(string $webhook, FeedbackPost $post, array $moduleLabels): void
    {
        $text = self::text($post, $moduleLabels);

        // After the response, so a slow or broken webhook never delays or fails the person's post.
        app()->terminating(function () use ($webhook, $text): void {
            try {
                Http::timeout(5)->post($webhook, ['text' => $text])->throw();
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    /**
     * @param  array<string, string>  $moduleLabels
     */
    public static function text(FeedbackPost $post, array $moduleLabels): string
    {
        $modules = collect($post->modules ?? [])->map(fn (string $slug): string => $moduleLabels[$slug] ?? $slug)->join(', ');

        $heading = collect([
            '*'.$post->kind->shortLabel().'* from '.$post->authorName(),
            $modules ?: null,
            $post->mood?->getLabel(),
        ])->filter()->join(' · ');

        $lines = [$heading, '> '.Str::limit(Str::squish($post->body), 280)];

        if ($url = FilamentFeedbackPlugin::inboxUrl()) {
            $lines[] = "<{$url}|Open the feedback inbox in ".config('app.name').'>';
        }

        return implode("\n", $lines);
    }
}
