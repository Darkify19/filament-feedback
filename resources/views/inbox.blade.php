<x-filament-panels::page>
    @include('filament-feedback::board', ['board' => $this->getBoard()])

    {{ $this->table }}

    {{-- Filament 3 in SPA mode starts a table below the fold only once it scrolls into view, and keeps
         it x-ignore'd until then. A Livewire update first (the 30s poll, any action) strips that
         attribute and Alpine starts the table's insides without the table. Keep it on until it loads.
         The script must start with `if`: Livewire runs it through Alpine's evaluator. --}}
    @script
    <script>
        if (! window.ffbKeepsLazyIgnore) {
            window.ffbKeepsLazyIgnore = true
            Livewire.hook('morph.updating', ({ el, toEl }) => {
                if (el.hasAttribute?.('x-load') && el.hasAttribute('x-ignore') && ! toEl.hasAttribute('x-ignore')) {
                    toEl.setAttribute('x-ignore', '')
                }
            })
        }
    </script>
    @endscript
</x-filament-panels::page>
