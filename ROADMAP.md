# Filament Feedback — Roadmap to v1.0

**Status (2026-11-10):** v0.1 is built, redesigned (quick sheet + My feedback page,
app icons, reactions, timeline, "did this fix it", anime.js motion) and green on
Filament 3.3, 4 and 5 (16 Pest tests, run locally against all three, and looked at in a
browser on 3 and 5). On GitHub as `Darkify19/filament-feedback`, tagged `v0.4.0` (module picker and `#` mentions in 0.2; Enter to send, unsend and
inbox stats in 0.3; "For the developers" and the redesigned sheet in 0.4). UPLB-TKS
requires `^0.4` through a VCS repository.

**Next up:** CI green on GitHub, then the TKS pilot on both panels. Packagist after that,
so apps can drop the VCS repository entry.

**Finish line, v1.0.0:** the package runs in at least three apps on three different
Filament majors, a teammate installs it from the README alone, and the v1.0 checklist
at the bottom is ticked. After 1.0, new features are minor releases. Breaking the
plugin API or the tables needs a major.

---

## Decisions already made

Don't relitigate these in a new session. Change them only on purpose.

| Decision | Why |
|---|---|
| **Quick sheet for posting, a page for threads.** | Posting stays on the page it's about, so `moduleFromRoute()` and "Sent from" keep working. Threads, reactions and history need room and a URL per thread for notifications. Picked over slide-over-only and page-only on 2026-11-10. |
| **A fixed set of reactions, no emoji picker.** | One tap to acknowledge, and counts that stay meaningful. |
| **`#` tags modules, `@` is kept for people.** | A wall of 12+ chips was too much (TKS). Picker + `#Module` write the same field; the server also parses `#` so a mention typed without the suggestions still counts. `@mentions` arrive with rich text (v0.3). |
| **Our own package, not Commentions.** | Commentions only comments on an existing record. We need standalone posts with modules, mood and status. |
| **Copy Commentions' engine later, don't depend on it.** | It's MIT. When rich text and @mentions arrive (v0.3), copy its sanitizer, mention parser and reactions, and keep Kirschbaum's notice in `NOTICE`. |
| **Plain text bodies until v0.3.** | Text escaped with `e()` needs no HTML sanitizer. The sanitizer arrives with rich text, not before. |
| **One inbox per app.** | Each app stores its own feedback. A shared inbox across apps waits until three apps use the package. |
| **Private by default.** | A post is seen by its author and the triagers only. A public board with "me too" is a per-app option for later (v0.3). |
| **Google is optional, in tiers.** | Tier 1, a Chat webhook ping, ships in v0.1. Tier 2, a two-way Chat bridge, needs Workspace admin approval and a service-account key, so it's built only if a team asks. |
| **One codebase for Filament 3.3, 4 and 5.** | 8 UPLB apps run on 3, 3 on 4 and 10 on 5. Same rules as `filament-insights`. |
| **No Tailwind in the package.** | Host builds never scan vendor views. Layout is plain CSS in `resources/css/feedback.css`, published by `filament:assets`. Colours come in through `Compat::color()`. |

## Cross-version rules (each one cost a debug round somewhere)

- Override **methods**, not static properties (`getView()`, `getNavigationIcon(): ?string`).
  Property types differ between majors and a redeclared property is a fatal error.
- Form state for enum options is an **enum on 4/5 and a string on 3**. Go through
  `FeedbackKind::fromState()` or an `instanceof` check, never `=== 'feedback'`.
- Closures take an **untyped `$get`**: `Get` changed namespace in 4.
- Row and notification actions go through `Support\Compat` (`Tables\Actions\Action` and
  `Notifications\Actions\Action` exist only on 3).
- Register every Livewire component with `Livewire::component()`, or lazy and update
  requests return a 500 on Livewire 3.
- Never put Blade `@if` or `@foreach` inside an HTML tag. Livewire injects block markers
  there. Use a `ComponentAttributeBag` or a ternary echo.
- Filament 3 doesn't validate each item of a multiple `ToggleButtons`. Filter the
  submitted values server-side (the launcher intersects modules with the configured list).
- Migrations are checked against MySQL, not just SQLite: a unique index over two 255-char
  morph types passes SQLite and dies on MySQL's 3072-byte key limit, and MySQL can't roll
  back DDL, so the half-applied migration then fails every deploy ("column already exists").
  Every migration checks `hasColumn` / `hasTable` before it changes anything.
- On a Livewire update request no panel is "current". Resolve the user through
  `Filament::getPanel($this->panel)->auth()`.

## Shipping to an app

A path repository (`"url": "../filament-feedback"`) works only on a laptop that has the
sibling folder. Before an app deploys, the package must be on GitHub and the app must
require a tag, through VCS or Packagist. Never push an app's `composer.json` with a path repo.

On Packagist since 2026-11-10, so apps just `composer require carljanzell/filament-feedback:^0.4`. Apps that added the VCS repository earlier can drop it:

```json
"repositories": [{ "type": "vcs", "url": "https://github.com/Darkify19/filament-feedback" }],
"require": { "carljanzell/filament-feedback": "^0.4" }
```

To keep editing the package live on a laptop, use a local `composer.json` override or
`COMPOSER=composer.local.json`, never the committed file.

---

## v0.1 — TKS redesign pilot (built, not tagged)

| Item | State |
|---|---|
| Floating Feedback button + slide-over on any panel (`->button()`) | ✅ |
| Post kinds: feedback, question, problem | ✅ |
| Modules (multi-select), pre-selected from the current route (`->moduleFromRoute()`) | ✅ |
| Mood, required on feedback (−2 angry … +2 love it) | ✅ |
| Context saved per post: page URL, route, user agent, `config('app.version')` | ✅ |
| "Your posts" list with threads, author replies, unread badge, `#feedback` auto-open | ✅ |
| Inbox page (`->inbox()`, `->canTriage()`): table, filters, slide-over thread, reply + status | ✅ |
| Mood × module board on the inbox | ✅ |
| Database notification to the author on a team reply | ✅ |
| `FeedbackPosted` / `ReplyPosted` events | ✅ |
| Google Chat webhook ping (`->chatWebhook()`) | ✅ |
| Quick sheet anchored to the button (bottom sheet on phones) | ✅ |
| My feedback page: threads, replies, `?post=` links from notifications | ✅ |
| Module icons from the app's navigation (or `'icon'` in `modules()`) | ✅ |
| Compact module picker + `#Module` mentions with autocomplete (v0.2.0) | ✅ |
| Enter sends a reply; unsend own replies (`allowUnsend()`); inbox stats widget (v0.3.0) | ✅ |
| "For the developers" kind (`developerPosts()`); kind boxes + one-row mood scale; phone sheet with a grab handle (v0.4.0) | ✅ |
| Reactions on posts and replies (Thanks, Same here, Seen, Fixed) | ✅ |
| Status timeline (`status_log`) and "Did this fix it?" | ✅ |
| anime.js motion, loaded on request, off with reduced motion | ✅ |
| Pest suite green on Filament 3.3, 4 and 5 | ✅ local, CI on first push |
| Looked at in a browser (dev host, Filament 3 and 5, light + dark, phone width) | ✅ |
| Look at it on UPLB-TKS (both panels) | ⬜ |
| Push to GitHub, tag `v0.1.0` | ✅ |
| Switch UPLB-TKS from the path repo to the tag, then deploy | ✅ composer only; deploy ⬜ |

## v0.2 — Prove it on Filament 4 and 5

| Item | Notes |
|---|---|
| Install in `tks` (Filament 5) and `KIOSK_UPLB` (Filament 4) | Fix what only a real app shows |
| Board period filter ("since") | So "how did *this* release land" excludes older posts. Default: since the newest post's month |
| Click a board cell to filter the table | Module + mood |
| Notify triagers on a new post (`->notifyTriagers(fn (): iterable)`) | Today only the event and the Chat ping fire |
| Translations (`lang/en`) | All copy currently inline |
| Publish to Packagist, README screenshots | Screenshots, docs site and listing image ✅ (`art/`, `docs/`, 2026-11-10). Packagist ✅ (auto-updated); Filament directory listing submitted, pending approval |

## v0.3 — Teammates' apps

| Item | Notes |
|---|---|
| Screenshot upload on a post | Filament FileUpload to the app's default disk, shown in the thread. Browser/screen context too |
| "Fixed in release X" | Link a Done post to a host changelog entry; board before/after a release |
| Saved replies for triagers | Picked from a menu in the inbox reply box |
| Rich text replies + @mentions for triagers | Copy Commentions' `SanitizeCommentHtml`, `ParseComment`, `suggestion.js` (MIT, keep notice) |
| "Me too" on posts | Needs the public board. Reactions shipped in v0.1 |
| Optional public board (`->public()`) | Others' posts readable and +1-able. Private stays the default |
| README written for a teammate who has never seen it | Install, both-panel setup, common recipes |

## Later, only if someone asks

- Tier 2 Google Chat bridge: HR replies from Chat and the reply lands in the app. See the
  direction memo for its blockers (admin approval, service-account key, polling).
- One shared inbox across apps.
- Export to CSV for sign-off meetings.

---

## v1.0 checklist (the finish line)

- [ ] Installed and used in ≥ 3 apps across Filament 3, 4 and 5
- [ ] CI green on the whole matrix (3.3 floor, 4 floor + latest, 5 floor + latest)
- [ ] v0.2 and v0.3 tables above all ✅
- [ ] A teammate installed it from the README with no help
- [ ] No open bug labelled `pilot` from the TKS rollout
- [ ] Plugin API and table schema reviewed once more, then frozen for 1.x

## Session log

| Date | What happened |
|---|---|
| 2026-11-09 | Repo created. v0.1 core built, tests green on 3.3.57, 4.16.2 and 5.11.2. Wired into UPLB-TKS (path repo) in place of its "Chat with us" popup. |
| 2026-11-10 | Run skill (Testbench dev host + Playwright driver). Redesign: quick sheet + My feedback page, nav icons, reactions, timeline, fix check, anime.js. Pushed to GitHub, tagged v0.1.0; TKS composer moved to the tag. Then v0.2.0: compact module picker + `#Module` mentions; v0.2.1 picker polish; v0.3.0: Enter to send, unsend, stats widget (filament-insights can place it); v0.4.0: "For the developers", kind boxes, mood scale, phone sheet. v0.4.1: the reactions migration broke TKS deploys on MySQL (key too long, then half-applied); made re-runnable, unique index dropped. |
| 2026-11-11 | v0.5.4: the sidebar menu and the page get room at the bottom while the button shows, so it never sits on a last nav item or a form's Save. TKS dropped its "clear the sidebar" offset and shows an icon-only button in the collapsed rail. |
