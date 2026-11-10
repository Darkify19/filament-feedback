@props(['name', 'time', 'body', 'team' => false, 'reactable' => null, 'viewer' => null])

@php
    use CarlJanzell\FilamentFeedback\Models\FeedbackPost;

    $type = $reactable instanceof FeedbackPost ? 'post' : 'reply';
    // People react to the other side's messages, not their own.
    $canReact = $reactable !== null && $viewer !== null && ! $reactable->isWrittenBy($viewer);
    $summary = $reactable?->reactionSummary($viewer) ?? [];
@endphp

<article
    {{ $attributes->class(['ffb-message', 'ffb-message-team' => $team]) }}
    style="--ffb-accent: {{ \CarlJanzell\FilamentFeedback\Support\Compat::color('primary') }}"
>
    <header class="ffb-message-meta">
        <span class="ffb-message-name">{{ $name }}</span>
        <time datetime="{{ $time->toIso8601String() }}" title="{{ $time->toDayDateTimeString() }}">{{ $time->diffForHumans() }}</time>
    </header>
    <div class="ffb-message-body">{!! nl2br(e($body)) !!}</div>

    @if ($reactable && ($canReact || collect($summary)->sum('count') > 0))
        <div class="ffb-reactions" x-data="{ more: false }">
            @foreach ($summary as $item)
                @if ($item['count'] > 0)
                    <button
                        type="button"
                        class="ffb-react"
                        aria-pressed="{{ $item['mine'] ? 'true' : 'false' }}"
                        title="{{ $item['reaction']->getLabel() }}"
                        {{ $canReact ? '' : 'disabled' }}
                        wire:click="react('{{ $type }}', {{ $reactable->getKey() }}, '{{ $item['reaction']->value }}')"
                    >
                        <x-filament::icon :icon="$item['reaction']->getIcon()" class="ffb-icon" />
                        <span>{{ $item['reaction']->getLabel() }}</span>
                        <span class="ffb-react-count">{{ $item['count'] }}</span>
                    </button>
                @endif
            @endforeach

            @if ($canReact)
                <button type="button" class="ffb-react ffb-react-add" x-on:click="more = ! more" x-bind:aria-expanded="more" aria-label="React">
                    <x-filament::icon icon="heroicon-o-face-smile" class="ffb-icon" />
                    <x-filament::icon icon="heroicon-m-plus" class="ffb-icon ffb-icon-sm" />
                </button>
                <span class="ffb-react-picker" x-show="more" x-cloak x-on:click.outside="more = false">
                    @foreach ($summary as $item)
                        @if ($item['count'] === 0)
                            <button
                                type="button"
                                class="ffb-react"
                                aria-pressed="false"
                                x-on:click="more = false"
                                wire:click="react('{{ $type }}', {{ $reactable->getKey() }}, '{{ $item['reaction']->value }}')"
                            >
                                <x-filament::icon :icon="$item['reaction']->getIcon()" class="ffb-icon" />
                                <span>{{ $item['reaction']->getLabel() }}</span>
                            </button>
                        @endif
                    @endforeach
                </span>
            @endif
        </div>
    @endif
</article>
