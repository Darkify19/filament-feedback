/**
 * Motion for filament-feedback, on anime.js (vendor/anime.esm.min.js, v4.5.0).
 *
 * Optional polish: the launcher imports this only when the viewer allows motion, and every
 * screen works the same, unanimated, if it never loads. Five moments, nothing else:
 * the sheet's options arrive, a pick pops, new messages and picked modules settle in, a
 * reaction pops, and the
 * unread badge pulses once. Inline styles are removed when each animation ends, so nothing
 * is left fighting Filament's own transitions.
 */

const SHEET = '.ffb-sheet'
const PICKS = `${SHEET} label.fi-btn, ${SHEET} .fi-fo-toggle-buttons label, .ffb-picker-option, .ffb-picker-add, .ffb-react, .ffb-item`
const ARRIVING = '.ffb-message, .ffb-timeline li, .ffb-fix, .ffb-notice, .ffb-picker-chip'

export default ({ animate, stagger, spring }) => {
    const gentle = spring({ bounce: 0.15, duration: 520 })
    const springy = spring({ bounce: 0.5, duration: 420 })
    const seen = new WeakSet()

    const release = (elements, ...properties) => () => [elements].flat().forEach((element) =>
        properties.forEach((property) => element.style.removeProperty(property)))

    const reveal = (elements, step = 28) => {
        const fresh = [...elements].filter((element) => ! seen.has(element) && seen.add(element))

        if (fresh.length) {
            animate(fresh, {
                opacity: { from: 0 },
                translateY: { from: 10 },
                delay: stagger(step),
                ease: gentle,
                onComplete: release(fresh, 'opacity', 'transform'),
            })
        }
    }

    const pop = (element) => animate(element, {
        scale: [{ to: 0.9, duration: 90, ease: 'outQuad' }, { to: 1, ease: springy }],
        onComplete: release(element, 'transform'),
    })

    const sheetOptions = (root) => root.querySelectorAll(`${SHEET} .fi-fo-toggle-buttons label, ${SHEET} label.fi-btn`)

    const start = () => {
        if (window.ffbMotionStarted) {
            return
        }

        window.ffbMotionStarted = true

        // Whatever is already on screen counts as seen; My feedback gets one entrance.
        document.querySelectorAll(ARRIVING).forEach((element) => seen.add(element))
        const page = document.querySelector('.ffb-page')

        if (page) {
            page.querySelectorAll('.ffb-message, .ffb-timeline li').forEach((element) => seen.delete(element))
            reveal(page.querySelectorAll('.ffb-item'), 40)
            reveal(page.querySelectorAll('.ffb-timeline li, .ffb-message'), 60)
        }

        document.querySelectorAll('.ffb-launcher-button .fi-badge, .ffb-launcher-button [class*="badge"]').forEach((badge) => animate(badge, {
            scale: [{ to: 1.35, duration: 180, delay: 600, ease: 'outQuad' }, { to: 1, ease: springy }],
            onComplete: release(badge, 'transform'),
        }))

        document.addEventListener('click', (event) => {
            const target = event.target.closest(PICKS)

            if (target) {
                pop(target.matches('.ffb-react') ? (target.querySelector('svg') ?? target) : target)
            }
        })

        // Livewire morphs new nodes in: the sheet's form when it opens, a new reply, another thread.
        new MutationObserver((mutations) => {
            const added = mutations.flatMap(({ addedNodes }) => [...addedNodes]).filter((node) => node.nodeType === Node.ELEMENT_NODE)

            if (! added.length) {
                return
            }

            const arriving = added.flatMap((node) => [...(node.matches(ARRIVING) ? [node] : []), ...node.querySelectorAll(ARRIVING)])
            reveal(arriving, 50)

            reveal(added.flatMap((node) => [...sheetOptions(node)]), 22)
        }).observe(document.body, { childList: true, subtree: true })
    }

    return { start, pop, reveal }
}
