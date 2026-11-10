# Agent guide: filament-feedback

A Filament plugin (not an app) for in-app feedback. One codebase for Filament 3.3, 4 and 5.

- **Using the plugin in an app?** Read [resources/boost/guidelines/core.blade.php](resources/boost/guidelines/core.blade.php).
  Apps with [Laravel Boost](https://github.com/laravel/boost) get it in their own agent guidelines automatically on `php artisan boost:install` / `boost:update`.
- **Working on the plugin itself?** Read on.

## Commands

```bash
composer install
composer test          # Pest, Testbench (tests/Feature/FeedbackTest.php)
composer lint          # Pint, check only
```

To see it in a browser (Testbench host app + headless Chrome, screenshots), use the
[run-filament-feedback skill](.claude/skills/run-filament-feedback/SKILL.md).

## Where things are

| Path | What |
|---|---|
| `src/FilamentFeedbackPlugin.php` | Every config method, URL helpers, `#module` mention parsing |
| `src/Livewire/FeedbackLauncher.php` | Floating button + quick sheet; creates posts |
| `src/Pages/FeedbackInbox.php` | Triager inbox: table, thread, reply, status, mood board |
| `src/Pages/MyFeedback.php` | Author's threads, reactions, timeline, "did this fix it" |
| `src/Models/` | `FeedbackPost`, `FeedbackReply`, `FeedbackReaction` (polymorphic authors) |
| `src/Support/Compat.php` | Class names that differ between Filament 3 and 4/5 |
| `resources/css/feedback.css` | All styling (plain CSS, no Tailwind) |
| `resources/boost/guidelines/core.blade.php` | Agent-facing usage guide shipped to apps |
| `tests/Fixtures/` | Test panel, user model, a Leave page |

## Rules

- Read **Decisions already made** and **Cross-version rules** in [ROADMAP.md](ROADMAP.md)
  before changing behaviour. They are settled; change them only on purpose.
- Any change must work on Filament 3.3, 4 and 5. CI runs the matrix in `.github/workflows/ci.yaml`.
- No Tailwind classes in views: host builds never scan vendor. Style in `resources/css/feedback.css`.
- When the public API changes (a plugin method, event, column, enum case), update
  `README.md` and `resources/boost/guidelines/core.blade.php` in the same commit.
