@php
    use CarlJanzell\FilamentFeedback\Support\Compat;
@endphp

{{-- What kind of post: one box per kind, icon + name + a line that says what it's for. --}}
<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        class="ffb-kinds"
        role="radiogroup"
        aria-label="What is it?"
        x-data="{ state: $wire.$entangle(@js($getStatePath()), true) }"
    >
        @foreach ($kinds as $kind)
            <button
                type="button"
                role="radio"
                class="ffb-kind"
                style="--ffb-tone: {{ Compat::color($kind->getColor() === 'gray' ? 'primary' : $kind->getColor(), 600) }}"
                x-bind:aria-checked="state === @js($kind->value)"
                x-on:click="state = @js($kind->value)"
            >
                <span class="ffb-kind-icon">
                    <x-filament::icon :icon="$kind->getIcon()" class="ffb-icon" />
                </span>
                <span class="ffb-kind-text">
                    <span class="ffb-kind-label">{{ $kind->getLabel() }}</span>
                    <span class="ffb-kind-hint">{{ $kind->getDescription() }}</span>
                </span>
            </button>
        @endforeach
    </div>
</x-dynamic-component>
