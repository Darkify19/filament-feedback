@php
    use CarlJanzell\FilamentFeedback\Enums\FeedbackMood;
    use CarlJanzell\FilamentFeedback\Support\Compat;
@endphp

{{-- A five-step face scale, love it → angry, in one row at every width. --}}
<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        class="ffb-moods"
        role="radiogroup"
        aria-label="How do you feel about it?"
        x-data="{ state: $wire.$entangle(@js($getStatePath())) }"
    >
        @foreach (FeedbackMood::cases() as $mood)
            <button
                type="button"
                role="radio"
                class="ffb-mood"
                style="--ffb-tone: {{ Compat::color($mood->getColor(), 600) }}"
                x-bind:aria-checked="String(state) === @js((string) $mood->value)"
                x-on:click="state = @js($mood->value)"
            >
                <x-filament::icon :icon="$mood->getIcon()" class="ffb-icon ffb-mood-icon" />
                <span>{{ $mood->getLabel() }}</span>
            </button>
        @endforeach
    </div>
</x-dynamic-component>
