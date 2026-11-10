{{--
    The modules field of the quick sheet. Picked modules show as chips; "Add" opens a short
    searchable list; typing # in the message suggests modules and adds the one picked.
    Both write the same `modules` state. The server also reads #Module from the message, so
    a mention typed without the suggestions still counts.
--}}
<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        class="ffb-picker"
        style="--ffb-accent: {{ \CarlJanzell\FilamentFeedback\Support\Compat::color('primary', 600) }}"
        x-data="{
            state: $wire.$entangle(@js($getStatePath())),
            options: @js($options),
            open: false,
            query: '',
            mention: null,
            active: 0,
            textarea: null,
            get picked() { return Array.isArray(this.state) ? this.state : [] },
            option(slug) { return this.options.find((option) => option.slug === slug) },
            filter(query) {
                const needle = (query ?? '').toLowerCase().replace(/[^a-z0-9]/g, '')
                return this.options.filter((option) => ! needle
                    || option.mention.toLowerCase().includes(needle)
                    || option.slug.toLowerCase().includes(needle))
            },
            toggle(slug) {
                this.state = this.picked.includes(slug) ? this.picked.filter((s) => s !== slug) : [...this.picked, slug]
            },
            add(slug) {
                if (! this.picked.includes(slug)) this.state = [...this.picked, slug]
            },
            get suggestions() { return this.mention ? this.filter(this.mention.query).slice(0, 6) : [] },
            init() {
                this.$nextTick(() => {
                    const textarea = this.$root.closest('.fi-modal-window, form')?.querySelector('textarea')
                    if (! textarea) return
                    this.textarea = textarea
                    textarea.addEventListener('input', () => this.track())
                    textarea.addEventListener('click', () => this.track())
                    textarea.addEventListener('keydown', (event) => this.keys(event))
                    textarea.addEventListener('blur', () => setTimeout(() => this.mention = null, 150))
                })
            },
            track() {
                const textarea = this.textarea
                const typed = textarea.value.slice(0, textarea.selectionStart).match(/(^|\s)#([\w-]*)$/)
                if (! typed) return this.mention = null
                const box = textarea.getBoundingClientRect()
                this.mention = { start: textarea.selectionStart - typed[2].length - 1, query: typed[2], top: box.bottom + 6, left: box.left }
                this.active = 0
            },
            keys(event) {
                if (! this.mention || ! this.suggestions.length) return
                const count = this.suggestions.length
                if (event.key === 'ArrowDown') { event.preventDefault(); this.active = (this.active + 1) % count }
                else if (event.key === 'ArrowUp') { event.preventDefault(); this.active = (this.active + count - 1) % count }
                else if (event.key === 'Enter' || event.key === 'Tab') { event.preventDefault(); this.pick(this.suggestions[this.active]) }
                else if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); this.mention = null }
            },
            pick(option) {
                const textarea = this.textarea
                const text = '#' + option.mention + ' '
                textarea.value = textarea.value.slice(0, this.mention.start) + text + textarea.value.slice(textarea.selectionStart)
                const caret = this.mention.start + text.length
                textarea.dispatchEvent(new Event('input', { bubbles: true }))
                textarea.focus()
                textarea.setSelectionRange(caret, caret)
                this.add(option.slug)
                this.mention = null
            },
        }"
    >
        <div class="ffb-picker-row">
            <template x-for="slug in picked" x-bind:key="slug">
                <span class="ffb-picker-chip">
                    <span class="ffb-picker-icon" x-html="option(slug)?.icon ?? ''"></span>
                    <span x-text="option(slug)?.label ?? slug"></span>
                    <button type="button" class="ffb-picker-remove" x-on:click="toggle(slug)" x-bind:aria-label="'Remove ' + (option(slug)?.label ?? slug)">
                        <x-filament::icon icon="heroicon-m-x-mark" class="ffb-icon ffb-icon-sm" />
                    </button>
                </span>
            </template>

            <button type="button" class="ffb-picker-add" x-on:click="open = ! open; query = ''; open && $nextTick(() => $refs.search.focus())" x-bind:aria-expanded="open">
                <x-filament::icon icon="heroicon-m-plus" class="ffb-icon ffb-icon-sm" />
                <span x-text="picked.length ? 'Add' : 'Pick a part'"></span>
            </button>

            <span class="ffb-picker-hint" x-show="! open">or type <kbd>#</kbd> in your message</span>
        </div>

        <div class="ffb-picker-panel" x-show="open" x-cloak x-on:keydown.escape.stop.prevent="open = false">
            <input
                type="search"
                class="ffb-picker-search"
                placeholder="Search parts of the app"
                aria-label="Search parts of the app"
                x-ref="search"
                x-model="query"
                x-on:keydown.enter.prevent="filter(query)[0] && toggle(filter(query)[0].slug)"
            >
            <div class="ffb-picker-options" role="listbox" aria-multiselectable="true">
                <template x-for="item in filter(query)" x-bind:key="item.slug">
                    <button
                        type="button"
                        role="option"
                        class="ffb-picker-option"
                        x-bind:aria-selected="picked.includes(item.slug)"
                        x-on:click="toggle(item.slug)"
                    >
                        <span class="ffb-picker-icon" x-html="item.icon"></span>
                        <span x-text="item.label"></span>
                        <x-filament::icon icon="heroicon-m-check" class="ffb-icon ffb-picker-check" />
                    </button>
                </template>
                <p class="ffb-picker-empty" x-show="! filter(query).length">No part of the app matches.</p>
            </div>
        </div>

        <div
            class="ffb-mention-list"
            role="listbox"
            aria-label="Tag a part of the app"
            x-show="suggestions.length"
            x-cloak
            x-bind:style="mention && { top: mention.top + 'px', left: mention.left + 'px' }"
        >
            <template x-for="(item, index) in suggestions" x-bind:key="item.slug">
                <button
                    type="button"
                    role="option"
                    class="ffb-picker-option"
                    x-bind:aria-selected="index === active"
                    x-on:mousedown.prevent="pick(item)"
                >
                    <span class="ffb-picker-icon" x-html="item.icon"></span>
                    <span x-text="item.label"></span>
                    <span class="ffb-mention-text" x-text="'#' + item.mention"></span>
                </button>
            </template>
        </div>
    </div>
</x-dynamic-component>
