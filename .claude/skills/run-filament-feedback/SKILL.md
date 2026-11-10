---
name: run-filament-feedback
description: Run, serve, launch, click through, or screenshot the filament-feedback plugin in a real browser (Feedback button quick sheet, module picker and #mentions, My feedback page, reactions, triage inbox, mood board) via a throwaway Testbench host app, on Filament 3, 4 or 5, and run its Pest tests. Use when asked to "run filament-feedback", "see the plugin in a browser", "screenshot the feedback sheet/inbox/My feedback", "post feedback and reply", "test on Filament 3", or verify a UI change to the plugin.
---

# Run filament-feedback

This repo is a Filament plugin, not an app, so the skill brings its own host:
`server.php` boots Testbench's Laravel skeleton with the test fixture panel
(`/testing`: plugin with button + inbox, modules DTR/Leave, a Leave page), a file SQLite DB
and two users. `driver.mjs` drives it in headless Chrome (Playwright) and writes
screenshots. Paths below are relative to the repo root.

Seeded users (the `<user>` argument is the id):

| id | who | sees |
|---|---|---|
| 1 | Ana, staff | Dashboard, Leave, My feedback, floating Feedback button |
| 2 | Ben, triager (`is_admin`) | also the Feedback inbox at `/testing/feedback` |

## Prerequisites (one-time)

PHP 8.2+, Composer, Node 18+, Google Chrome (macOS: `/Applications/Google Chrome.app`).

```bash
composer install
npm i --no-save --no-package-lock --prefix .claude/skills/run-filament-feedback playwright-core@1.56.1
```

## Run (agent path)

```bash
# 1. Fresh DB + migrations + seed + publish Filament/plugin assets (CSS, anime.js, motion.js). Re-run to reset.
php .claude/skills/run-filament-feedback/server.php setup

# 2. Serve (keep it running in the background). -t is required: it's where assets were published.
php -S 127.0.0.1:8123 -t vendor/orchestra/testbench-core/laravel/public .claude/skills/run-filament-feedback/server.php

# 3. Drive it. Screenshots go to $FFB_SHOTS (default: OS temp dir); the path is printed.
export FFB_SHOTS=/some/dir
D=.claude/skills/run-filament-feedback/driver.mjs
node $D shot 1 '/testing/leave#feedback' sheet.png                    # quick sheet, Leave pre-selected from the page
node $D post 1 "The time-out column wraps on my phone." DTR Frustrated  # sheet -> picker Add -> DTR -> mood -> Send
node $D click 1 '/testing/leave#feedback' '.fi-modal-open textarea' 'type=Slow on #d' 'key=Enter' --out mention.png  # # suggestions -> DTR tagged
node $D reply 2 "Thanks Ana, a mobile fix is planned." Done             # inbox -> newest row -> reply + status -> Save
node $D click 1 /testing/my-feedback '.ffb-react-add' '.ffb-react-picker >> text=Thanks' --out my.png
node $D click 2 /testing/feedback '.fi-ta-row' --out inbox-thread.png   # triager's thread with timeline + reactions
FFB_PHONE=1 FFB_DARK=1 node $D shot 1 /testing/my-feedback phone-dark.png
node $D eval 1 /testing/my-feedback "new Promise(r => setTimeout(() => r(!!window.ffbMotionStarted), 1500))"  # anime.js loaded?

# 4. Stop
pkill -f "php -S 127.0.0.1:8123"
```

`click` runs steps in order and waits for Livewire to settle after each: a Playwright
locator is clicked, `type=<text>` types into the focused field key by key (so the `#`
suggestions fire; `fill()` would not), `key=<Key>` presses a key. `type <user> <path>
<locator> <text>` is the one-step version. `post` writes `ffb-post-filled.png` / `ffb-post-sent.png`, `reply` writes
`ffb-reply-filled.png` / `ffb-reply-saved.png`; on any failure the driver saves
`ffb-error.png` before rethrowing. Check the DB directly with
`sqlite3 $TMPDIR/filament-feedback-run.sqlite 'select id,status,status_log,fix_confirmed from feedback_posts'`
(override the location with `FFB_DB=/path.sqlite` on both `setup` and `php -S`).

To log in by hand in a real browser: open `http://127.0.0.1:8123/_as/2` (optionally
`?to=/testing/feedback`). There is no login form.

## Run on Filament 3 or 4

`vendor/` holds one Filament line (5.x today). For another, make a sibling install that
links the live sources, and give it a real copy of `.claude` (`server.php` finds `vendor/`
from its own resolved path, so a symlinked `.claude` would boot the main repo's vendor):

```bash
M=/tmp/ffb-matrix/v3; R=$PWD; mkdir -p $M && cp composer.json phpunit.xml $M/ && cd $M
composer require --no-update -q "filament/filament:^3.3.53"
composer require --dev --no-update -q "livewire/livewire:^3.5" "orchestra/testbench:^10.0"
composer update -q --no-interaction --with-all-dependencies
for d in src tests database resources; do ln -sfn $R/$d $d; done; cp -R $R/.claude .claude
vendor/bin/pest --compact
FFB_DB=$M/run.sqlite APP_URL=http://127.0.0.1:8124 php .claude/skills/run-filament-feedback/server.php setup
FFB_DB=$M/run.sqlite APP_URL=http://127.0.0.1:8124 php -S 127.0.0.1:8124 -t vendor/orchestra/testbench-core/laravel/public .claude/skills/run-filament-feedback/server.php
FFB_URL=http://127.0.0.1:8124 node .claude/skills/run-filament-feedback/driver.mjs shot 1 /testing/my-feedback v3.png
```

Filament 4 is the same with `^4.13.2`, Livewire `^3.6`, Testbench `^10.0`.

## Test

```bash
vendor/bin/pest --compact      # 13 tests; no server needed
vendor/bin/pint --test         # CI runs this too
```

## Gotchas

- **The test fixture panel has no middleware.** `tests/Fixtures/TestPanelProvider` works for
  Pest (`actingAs`) but in a browser the session never sticks and `/testing` redirects to
  itself forever. `server.php` subclasses it (`RunPanelProvider`) to add the standard
  cookie/session/CSRF/auth middleware stack, plus Filament's Dashboard.
- **Config overrides go in `$app->booting()`**, not straight in Testbench's
  `resolvingCallback`: config isn't bound yet there (`Target class [config] does not exist`).
- **Filament modals look "hidden" to Playwright.** The `role=dialog` wrapper has no box, so
  `getByRole('dialog').waitFor()` times out even with the sheet on screen. Target
  `.fi-modal-open .fi-modal-window` (the driver's `openModal()`).
- **ToggleButtons are hidden inputs + `<label>`s.** Click the label text, not the input
  (kind and mood). Modules are not ToggleButtons any more: they're the custom picker
  (`resources/views/module-picker.blade.php`), so target `.ffb-picker-add` / `.ffb-picker-option`.
- **`#` suggestions listen to real key events.** Playwright `fill()` sets the value without
  them; use `type=` steps. The server parses `#Module` too, so a filled message still tags.
- **The sheet is Filament's own modal, re-positioned by CSS** (`:has(> .fi-modal-window.ffb-sheet)`
  in `resources/css/feedback.css`). On Filament 3 the form is indented under the heading
  icon; on 4/5 it isn't. That's Filament's layout, not a bug.
- **Module icons come from routes.** The Leave chip's sun icon is `LeavePage`'s navigation
  icon, found by running each panel GET route through `moduleFromRoute()`. A page the
  resolver doesn't map lends nothing.
- **Published assets and sessions live in `vendor/orchestra/testbench-core/laravel/`.**
  Any `composer install/update` wipes them; re-run `server.php setup`. Changed
  `resources/js` or `resources/css`? Re-run setup too: assets are copies, not links.
- **Toast notifications are gone by the time `post`/`reply` screenshot.** Verify via the
  DB or a follow-up `shot`, not the toast.

## Troubleshooting

| Symptom | Fix |
|---|---|
| `/testing` 302 loops to itself | Not logged in, or the panel lacks session middleware: hit `/_as/<id>` first; make sure `server.php` still registers `RunPanelProvider`. |
| `Cannot find package 'playwright-core'` | Run the `npm i --prefix .claude/skills/run-filament-feedback …` line. |
| `locator.waitFor: Timeout` on a modal | See the dialog gotcha above; then open `ffb-error.png`. PHP errors land in `vendor/orchestra/testbench-core/laravel/storage/logs/laravel.log`. |
