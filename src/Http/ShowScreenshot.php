<?php

namespace CarlJanzell\FilamentFeedback\Http;

use CarlJanzell\FilamentFeedback\Models\FeedbackPost;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a post's screenshot to its author or a triager who may read the post. Screenshots are
 * stored privately, so this route (under the panel's auth middleware) is the only way to them.
 */
class ShowScreenshot
{
    public function __invoke(string $post, int $index): StreamedResponse
    {
        $user = Filament::auth()->user();
        $plugin = Filament::getCurrentPanel()?->getPlugin('feedback');
        $post = FeedbackPost::query()->find($post);
        $screenshot = $post?->screenshots[$index] ?? null;

        abort_unless($screenshot !== null && $user !== null, 404);
        abort_unless(
            $post->isWrittenBy($user)
                || ($plugin->isTriager() && FeedbackPost::query()->visibleToTeam()->whereKey($post->getKey())->exists()),
            403,
        );

        $disk = Storage::disk($screenshot['disk']);
        abort_unless($disk->exists($screenshot['path']), 404);

        return $disk->response($screenshot['path'], headers: [
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
