@php
    $moduleNames = collect($post->modules ?? [])->map(fn ($slug) => $modules[$slug] ?? $slug);
    $context = $post->context ?? [];
@endphp

<div class="ffb-thread-panel" style="--ffb-accent: {{ \CarlJanzell\FilamentFeedback\Support\Compat::color('primary') }}">
    <dl class="ffb-facts">
        <div>
            <dt>Status</dt>
            <dd><x-filament::badge :color="$post->status->getColor()" :icon="$post->status->getIcon()">{{ $post->status->getLabel() }}</x-filament::badge></dd>
        </div>
        @if ($post->mood)
            <div>
                <dt>Mood</dt>
                <dd><x-filament::badge :color="$post->mood->getColor()" :icon="$post->mood->getIcon()">{{ $post->mood->getLabel() }}</x-filament::badge></dd>
            </div>
        @endif
        @if ($moduleNames->isNotEmpty())
            <div>
                <dt>Modules</dt>
                <dd class="ffb-chips">
                    @include('filament-feedback::module-chips')
                </dd>
            </div>
        @endif
        @if ($post->fix_confirmed !== null)
            <div>
                <dt>Author says</dt>
                <dd>
                    <x-filament::badge :color="$post->fix_confirmed ? 'success' : 'danger'" :icon="$post->fix_confirmed ? 'heroicon-o-check-circle' : 'heroicon-o-x-circle'">
                        {{ $post->fix_confirmed ? 'Fixed' : 'Not fixed' }}
                    </x-filament::badge>
                </dd>
            </div>
        @endif
        @if (filled($context['url'] ?? null))
            <div>
                <dt>Sent from</dt>
                <dd><a href="{{ $context['url'] }}" target="_blank" rel="noopener" class="ffb-link">{{ \Illuminate\Support\Str::limit(parse_url($context['url'], PHP_URL_PATH) ?: '/', 60) }}</a></dd>
            </div>
        @endif
        @if (filled($context['app_version'] ?? null))
            <div>
                <dt>Version</dt>
                <dd>{{ $context['app_version'] }}</dd>
            </div>
        @endif
    </dl>

    @if ($canImpersonate ?? false)
        <div>
            <x-filament::button
                size="sm"
                color="gray"
                icon="heroicon-m-eye"
                wire:click="impersonate({{ $post->getKey() }})"
                wire:confirm="Sign in as {{ $post->authorName() }}{{ filled($context['url'] ?? null) ? ' and open the page they sent this from' : '' }}? Leave from the banner when you're done."
            >
                View as {{ $post->authorName() }}
            </x-filament::button>
        </div>
    @endif

    @include('filament-feedback::timeline')

    <div class="ffb-thread">
        <x-filament-feedback::message
            wire:key="ffb-inbox-post-{{ $post->getKey() }}"
            :name="$post->authorName()"
            :time="$post->created_at"
            :body="$post->body"
            :reactable="$post"
            :viewer="$viewer"
        />

        @foreach ($post->replies as $reply)
            <x-filament-feedback::message
                wire:key="ffb-inbox-reply-{{ $reply->getKey() }}"
                :name="$reply->authorName()"
                :time="$reply->created_at"
                :body="$reply->body"
                :team="$reply->is_from_team"
                :reactable="$reply"
                :can-unsend="$canUnsend"
                :viewer="$viewer"
            />
        @endforeach
    </div>
</div>
