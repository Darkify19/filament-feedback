<ol class="ffb-timeline" aria-label="Progress">
    @foreach ($post->timeline() as $step)
        <li>
            <span class="ffb-timeline-dot"></span>
            <span class="ffb-timeline-label">{{ $step['label'] }}</span>
            <time datetime="{{ $step['at']->toIso8601String() }}" title="{{ $step['at']->toDayDateTimeString() }}">{{ $step['at']->format('M j, g:i A') }}</time>
        </li>
    @endforeach
</ol>
