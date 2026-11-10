# Filament Feedback

In-app feedback for Filament panels. People press **Feedback** on any page, and a small
sheet opens above the button: pick the parts of the app they mean and how they feel, and
write. Their threads live on a **My feedback** page, with the team's replies, reactions and
a Sent → Seen → Planned → Done timeline. The team reads every post in an inbox, replies and
sets a status. A board shows the mood for each module, so you can see how a release landed.

- Works on **Filament 3.3, 4 and 5** from one codebase.
- Private by default: a post is seen by its author and the people you let triage.
- Module chips use your app's own navigation icons.
- Reactions (Thanks, Same here, Seen, Fixed) and a "Did this fix it?" check once a post is Done.
- Motion by anime.js, loaded only when the viewer allows motion.
- Optional Google Chat ping for every new post.

> Pre-release. See [ROADMAP.md](ROADMAP.md) for what's in, what's next and what 1.0 means.

## Install

Not on Packagist yet: add the GitHub repository first.

```bash
composer config repositories.filament-feedback vcs https://github.com/Darkify19/filament-feedback
composer require carljanzell/filament-feedback:^0.1
php artisan migrate
php artisan filament:assets
```

## Set up

Register the plugin on each panel that needs it. A typical app has one panel everyone
uses (the button) and an admin panel (the inbox):

```php
use CarlJanzell\FilamentFeedback\FilamentFeedbackPlugin;

$modules = [
    'dtr' => 'DTR',
    'leave' => 'Leave',
    'design' => ['label' => 'Overall look', 'icon' => 'heroicon-o-swatch'],
];

// The panel staff use: the floating Feedback button and My feedback, no inbox.
$panel->plugin(
    FilamentFeedbackPlugin::make()
        ->modules($modules)
        ->moduleFromRoute(fn (?string $route): ?string => str_contains((string) $route, 'leave') ? 'leave' : null)
        ->inbox(false)
        ->chatWebhook(env('FEEDBACK_CHAT_WEBHOOK')),
);

// The admin panel: the inbox, no button.
$panel->plugin(
    FilamentFeedbackPlugin::make()
        ->modules($modules)
        ->button(false)
        ->canTriage(fn (): bool => auth()->user()?->hasRole('admin') ?? false)
        ->navigationGroup('System'),
);
```

A one-panel app keeps both on (the default) and only sets `canTriage()`.

| Method | Default | What it does |
|---|---|---|
| `modules(array\|Closure)` | `[]` | The parts of the app people can tag: `slug => label`, or `slug => ['label' => …, 'icon' => …]`. With none, the field is hidden. |
| `moduleFromRoute(Closure)` | none | Pre-selects a module. Receives `$route` and `$url` and returns a slug or null. |
| `canTriage(bool\|Closure)` | `false` | Who opens the inbox and replies as the team. |
| `button(bool)` | `true` | The floating Feedback button on every page, and the My feedback page. |
| `inbox(bool)` | `true` | The Feedback inbox page. |
| `launcherAttributes(array)` | `[]` | Extra attributes on the button's wrapper, for your own CSS. |
| `chatWebhook(?string)` | none | A Google Chat space webhook. Each new post sends it one line. |
| `navigationGroup(?string)` | none | Where the inbox sits in the navigation. |

Set it on the panel that has the button: posts are created there.

### Module icons

A module without an icon borrows one from your navigation: the plugin runs each page's and
resource's route through `moduleFromRoute()`, and the first page that maps to a module lends
it its navigation icon. So if `moduleFromRoute()` maps your DTR resource to `dtr`, the DTR
chip shows the same icon as the DTR item in the sidebar. An `'icon'` in `modules()` wins.

### Moving the button

It sits bottom-left, and the sheet opens just above it. Shift both with CSS variables on
`:root`:

```css
:root { --ffb-launcher-bottom: 5rem; --ffb-launcher-left: 1rem; }
```

### Google Chat ping

In a Chat space: space name → **Apps & integrations** → **Webhooks** → add one, then copy
its URL into `chatWebhook()`. The app only calls out, so this works from a server Google
can't reach, and it needs no Cloud project. If the option is missing, your Workspace admin
has turned off webhooks.

## Hooking in

- `CarlJanzell\FilamentFeedback\Events\FeedbackPosted` fires with `$post` on every new post.
  Listen to it to notify your triagers.
- `CarlJanzell\FilamentFeedback\Events\ReplyPosted` fires with `$reply` on both sides of a
  thread. `$reply->is_from_team` tells you which side.
- The author gets a Filament database notification when the team replies. Its link opens
  that thread on My feedback (`/my-feedback?post=…`). Turn on `->databaseNotifications()`
  on that panel. `notifyAuthorUsing()` receives the same `$url`.
- A link to `#feedback` on any page of the button's panel opens the sheet.

## Data

Three tables: `feedback_posts`, `feedback_replies` and `feedback_reactions`. Authors are
polymorphic (`morphs()`), so any authenticatable model works, UUID keys included.

## Testing

```bash
composer test
```

## License

MIT. See [LICENSE](LICENSE).
