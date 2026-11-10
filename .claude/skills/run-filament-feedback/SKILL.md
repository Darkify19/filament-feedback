---
name: run-filament-feedback
description: Run, serve, launch, click through, or screenshot the filament-feedback plugin in a real browser (Feedback button slide-over, triage inbox, mood board, reply thread) via a throwaway Testbench host app, and run its Pest tests. Use when asked to "run filament-feedback", "see the plugin in a browser", "screenshot the feedback button/inbox", "post feedback and reply", or verify a UI change to the plugin.
---

# Run filament-feedback

This repo is a Filament plugin, not an app, so the skill brings its own host:
`server.php` boots Testbench's Laravel skeleton with the test fixture panel
(`/testing`, plugin on with button + inbox, modules DTR/Leave), a file SQLite DB and two
users. `driver.mjs` drives it in headless Chrome (Playwright) and writes screenshots.
Paths below are relative to the repo root.

Seeded users (the `<user>` argument is the id):

| id | who | sees |
|---|---|---|
| 1 | Ana, staff | Dashboard + floating Feedback button |
| 2 | Ben, triager (`is_admin`) | also the Feedback inbox at `/testing/feedback` |

## Prerequisites (one-time)

PHP 8.2+, Composer, Node 18+, Google Chrome (macOS: `/Applications/Google Chrome.app`).

```bash
composer install
npm i --no-save --no-package-lock --prefix .claude/skills/run-filament-feedback playwright-core@1.56.1
```

## Run (agent path)

```bash
# 1. Fresh DB + migrations + seed + publish Filament/plugin assets. Re-run to reset.
php .claude/skills/run-filament-feedback/server.php setup

# 2. Serve (keep it running in the background). -t is required: it's where assets were published.
php -S 127.0.0.1:8123 -t vendor/orchestra/testbench-core/laravel/public .claude/skills/run-filament-feedback/server.php

# 3. Drive it. Screenshots go to $FFB_SHOTS (default: OS temp dir); the path is printed.
export FFB_SHOTS=/some/dir
D=.claude/skills/run-filament-feedback/driver.mjs
node $D shot 1 /testing ana-dash.png                                  # any page as any user
node $D post 1 "The DTR page is confusing on mobile." DTR Frustrated  # Feedback slide-over -> Send
node $D reply 2 "Thanks Ana, a mobile fix is planned." Planned        # inbox -> newest row -> reply + status -> Save
node $D shot 1 '/testing#feedback' ana-thread.png                     # author's slide-over: thread + "New reply"
node $D eval 2 /testing/feedback "document.title"                     # print any page expression (JSON)

# 4. Stop
pkill -f "php -S 127.0.0.1:8123"
```

`post` writes `ffb-post-filled.png` / `ffb-post-sent.png`, `reply` writes
`ffb-reply-filled.png` / `ffb-reply-saved.png`; on any failure the driver saves
`ffb-error.png` before rethrowing. Check the DB directly with
`sqlite3 $TMPDIR/filament-feedback-run.sqlite 'select id,status,modules,body from feedback_posts'`
(override the location with `FFB_DB=/path.sqlite` on both `setup` and `php -S`).

To log in by hand in a real browser: open `http://127.0.0.1:8123/_as/2` (optionally
`?to=/testing/feedback`). There is no login form.

## Test

```bash
vendor/bin/pest --compact      # 8 tests; no server needed
```

CI (`.github/workflows/ci.yaml`) runs the same suite across Filament 3.3/4/5 by
`composer require`-ing each line; the dev host runs whatever Filament is in `vendor/`
(currently 5.x / Livewire 4).

## Gotchas

- **The test fixture panel has no middleware.** `tests/Fixtures/TestPanelProvider` works for
  Pest (`actingAs`) but in a browser the session never sticks and `/testing` redirects to
  itself forever. `server.php` subclasses it (`RunPanelProvider`) to add the standard
  cookie/session/CSRF/auth middleware stack.
- **Staff need a page they can open.** The inbox is triager-only, so with only the inbox
  registered Ana gets `/testing` → 302 → `/testing` and `/testing/feedback` → 403.
  `RunPanelProvider` adds Filament's `Dashboard`.
- **Config overrides go in `$app->booting()`**, not straight in Testbench's
  `resolvingCallback` — config isn't bound yet there (`Target class [config] does not exist`).
- **Filament modals look "hidden" to Playwright.** The `role=dialog` wrapper has no box, so
  `getByRole('dialog').waitFor()` times out even with the slide-over on screen. Target
  `.fi-modal-open .fi-modal-window` (the driver's `openModal()`).
- **ToggleButtons are hidden inputs + `<label>`s.** Click the label text, not the input.
- **`moduleFromRoute` pre-select can't be seen here.** The fixture matches `/leave` in the
  URL, the host has no such page, and a `?x=/leave` query doesn't help: `fullUrl()`
  encodes it to `%2Fleave`.
- **Published assets and sessions live in `vendor/orchestra/testbench-core/laravel/`.**
  Any `composer install/update` wipes them; re-run `server.php setup`.
- **Toast notifications are gone by the time `post`/`reply` screenshot.** Verify via the
  DB or a follow-up `shot`, not the toast.

## Troubleshooting

| Symptom | Fix |
|---|---|
| `/testing` 302 loops to itself | Not logged in, or the panel lacks session middleware — hit `/_as/<id>` first; make sure `server.php` still registers `RunPanelProvider`. |
| `Cannot find package 'playwright-core'` | Run the `npm i --prefix .claude/skills/run-filament-feedback …` line. |
| `locator.waitFor: Timeout` on a modal | See the dialog gotcha above; then open `ffb-error.png`. PHP errors land in `vendor/orchestra/testbench-core/laravel/storage/logs/laravel.log`. |
