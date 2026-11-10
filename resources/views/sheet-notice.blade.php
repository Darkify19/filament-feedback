<a href="{{ $url }}" class="ffb-notice" style="--ffb-accent: {{ \CarlJanzell\FilamentFeedback\Support\Compat::color('primary') }}">
    <x-filament::icon icon="heroicon-o-chat-bubble-left-right" class="ffb-icon" />
    <span><strong>{{ $unread === 1 ? 'The team replied to a post' : "The team replied to {$unread} posts" }}.</strong> Read it in My feedback</span>
    <x-filament::icon icon="heroicon-m-arrow-right" class="ffb-icon" />
</a>
