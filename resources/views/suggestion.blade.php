{{-- The suggested reply's working notes, for triagers only: it sits right above the reply box the draft filled. --}}
<section class="ffb-suggestion" aria-label="Suggested reply" style="--ffb-warning: {{ \CarlJanzell\FilamentFeedback\Support\Compat::color('warning', 600) }}">
    <header class="ffb-suggestion-head">
        <x-filament::icon icon="heroicon-m-sparkles" class="ffb-suggestion-icon" />
        <strong>Suggested reply</strong>
        <x-filament::badge :color="$suggestion->confidence->getColor()" size="sm">{{ $suggestion->confidence->getLabel() }}</x-filament::badge>
        @if (filled($suggestion->drafted_by))
            <span class="ffb-suggestion-by">{{ $suggestion->drafted_by }} · {{ $suggestion->created_at->diffForHumans() }}</span>
        @endif
    </header>

    @if ($suggestion->isStale())
        <p class="ffb-suggestion-stale">
            <x-filament::icon icon="heroicon-m-exclamation-triangle" class="ffb-suggestion-icon" />
            Someone wrote in this thread after it was drafted. Read the newest message before you send it.
        </p>
    @endif

    @if (filled($suggestion->note))
        <p class="ffb-suggestion-note">{{ $suggestion->note }}</p>
    @endif

    @if (filled($suggestion->sources))
        <ul class="ffb-suggestion-sources" aria-label="Sources">
            @foreach ($suggestion->sources as $source)
                <li>{{ $source }}</li>
            @endforeach
        </ul>
    @endif

    <p class="ffb-suggestion-hint">
        {{ $suggestion->body === null ? 'No reply: it only sets the status.' : 'The draft is in the reply box below.' }}
        Only the team sees this note. Nothing reaches the author until you press Save.
    </p>
</section>
