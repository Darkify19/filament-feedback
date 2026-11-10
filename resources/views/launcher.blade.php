@php
    use CarlJanzell\FilamentFeedback\FeedbackServiceProvider;
    use Filament\Support\Facades\FilamentAsset;
@endphp

{{--
    Motion (resources/js/motion.js, on anime.js) loads once per page and only when the viewer
    allows motion. Everything works the same, unanimated, if it never loads.
--}}
<div
    class="ffb-root"
    data-anime-src="{{ FilamentAsset::getScriptSrc('anime', FeedbackServiceProvider::PACKAGE) }}"
    data-motion-src="{{ FilamentAsset::getScriptSrc('motion', FeedbackServiceProvider::PACKAGE) }}"
    x-data
    x-init="
        window.ffbMotion ??= matchMedia('(prefers-reduced-motion: reduce)').matches
            ? Promise.resolve(null)
            : Promise.all([import($el.dataset.animeSrc), import($el.dataset.motionSrc)])
                .then(([anime, motion]) => motion.default(anime), () => null)
        window.ffbMotion.then((motion) => motion?.start())
    "
    x-on:ffb-open-sheet.window="$wire.mountAction('feedback')"
>
    {{-- Attributes go through a bag: a Blade @if/@foreach inside a tag gets Livewire's block markers injected. --}}
    <div
        {{ (new \Illuminate\View\ComponentAttributeBag($launcherAttributes))->class('ffb-launcher') }}
        x-data
        x-init="if (location.hash === '#feedback') { history.replaceState(null, '', location.pathname + location.search); $wire.mountAction('feedback') }"
    >
        {{ $this->feedbackAction }}
    </div>

    {{-- Outside the floating wrapper, so an app hiding its floating buttons doesn't hide the sheet too. --}}
    <x-filament-actions::modals />
</div>
