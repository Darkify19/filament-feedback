@props(['name', 'time', 'body', 'team' => false, 'reactable' => null, 'viewer' => null, 'canUnsend' => false])

@php
    use CarlJanzell\FilamentFeedback\Models\FeedbackPost;

    $type = $reactable instanceof FeedbackPost ? 'post' : 'reply';
    $unsent = $type === 'reply' && $reactable?->isUnsent();
    // Only replies, only your own, and only where the plugin allows this side to.
    $unsendable = $canUnsend && $type === 'reply' && ! $unsent && $viewer !== null && $reactable->isWrittenBy($viewer);
    // People react to the other side's messages, not their own.
    $canReact = $reactable !== null && $viewer !== null && ! $reactable->isWrittenBy($viewer) && ! ($type === 'reply' && $reactable->isUnsent());
    $summary = $reactable?->reactionSummary($viewer) ?? [];

    // #Module mentions the current panel knows read as tags. Applied after escaping, so only
    // the plugin's own <span> is markup; (?<![\w&]) skips entities like &#039;.
    $panel = \Filament\Facades\Filament::getCurrentPanel();
    $tokens = $panel?->hasPlugin('feedback') ? $panel->getPlugin('feedback')->mentionTokens() : [];
    $html = preg_replace_callback(
        '/(?<![\w&])#([\pL\pN][\pL\pN_-]*)/u',
        fn (array $match): string => isset($tokens[\Illuminate\Support\Str::lower(preg_replace('/[^\pL\pN]+/u', '', $match[1]))])
            ? '<span class="ffb-mention">'.$match[0].'</span>'
            : $match[0],
        e($body),
    );
@endphp

<article
    {{ $attributes->class(['ffb-message', 'ffb-message-team' => $team]) }}
    style="--ffb-accent: {{ \CarlJanzell\FilamentFeedback\Support\Compat::color('primary') }}"
>
    <header class="ffb-message-meta">
        <span class="ffb-message-name">{{ $name }}</span>
        <span class="ffb-message-side">
            @if ($unsendable)
                <button
                    type="button"
                    class="ffb-unsend"
                    wire:click="unsend({{ $reactable->getKey() }})"
                    wire:confirm="Unsend this message? It disappears for everyone in this thread."
                >Unsend</button>
            @endif
            <time datetime="{{ $time->toIso8601String() }}" title="{{ $time->toDayDateTimeString() }}">{{ $time->diffForHumans() }}</time>
        </span>
    </header>
    @if ($unsent)
        <div class="ffb-message-body ffb-unsent">
            <x-filament::icon icon="heroicon-o-no-symbol" class="ffb-icon ffb-icon-sm" />
            {{ $viewer && $reactable->isWrittenBy($viewer) ? 'You unsent a message' : $name.' unsent a message' }}
        </div>
    @else
        <div class="ffb-message-body">{!! nl2br($html) !!}</div>
    @endif

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
