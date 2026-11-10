@foreach ($post->modules ?? [] as $slug)
    <span class="ffb-chip">
        @if ($icon = $moduleIcons[$slug] ?? null)
            <x-filament::icon :icon="$icon" class="ffb-icon" />
        @endif
        {{ $modules[$slug] ?? $slug }}
    </span>
@endforeach
