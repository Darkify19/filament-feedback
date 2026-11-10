## Filament Feedback (carljanzell/filament-feedback)

In-app feedback for Filament 3.3, 4 and 5 panels. A floating **Feedback** button opens a sheet where people pick a kind (feedback, question, problem, for the developers), tag modules and a mood, and write. Authors read their threads on **My feedback** (`/my-feedback`); triagers work from the **Feedback** inbox (`/feedback`), reply and set a status (New → Seen → Planned → Done). Private by default: a post is seen only by its author and triagers.

### Install

@verbatim
<code-snippet name="Install" lang="bash">
composer require carljanzell/filament-feedback:^0.4
php artisan migrate
php artisan filament:assets
</code-snippet>
@endverbatim

- `php artisan filament:assets` is required after install and every update: the CSS and JS are plain assets, not part of the app's Tailwind/Vite build. A sheet with no styling means this step was skipped.
- No config file, no publish step, no service provider to register (auto-discovered). All setup is on the plugin object.

### Register on a panel

@verbatim
<code-snippet name="One panel: button + inbox" lang="php">
use CarlJanzell\FilamentFeedback\FilamentFeedbackPlugin;

$panel->plugin(
    FilamentFeedbackPlugin::make()
        ->modules(['dtr' => 'DTR', 'leave' => 'Leave'])
        ->canTriage(fn (): bool => auth()->user()?->hasRole('admin') ?? false),
);
</code-snippet>
@endverbatim

@verbatim
<code-snippet name="Two panels: staff panel has the button, admin panel has the inbox" lang="php">
// Staff panel: posts are created here, so webhooks and moduleFromRoute() belong here.
FilamentFeedbackPlugin::make()
    ->modules($modules)
    ->moduleFromRoute(fn (?string $route, string $url): ?string => str_contains((string) $route, 'leave') ? 'leave' : null)
    ->inbox(false)
    ->chatWebhook(env('FEEDBACK_CHAT_WEBHOOK'));

// Admin panel: the inbox only.
FilamentFeedbackPlugin::make()
    ->modules($modules)
    ->button(false)
    ->canTriage(fn (): bool => auth()->user()?->isAdmin() ?? false)
    ->navigationGroup('System');
</code-snippet>
@endverbatim

Pass the same `modules()` to every panel that registers the plugin; the inbox needs the labels too.

### Plugin methods

| Method | Default | Notes |
|---|---|---|
| `modules(array\|Closure)` | `[]` | `slug => 'Label'` or `slug => ['label' => …, 'icon' => 'heroicon-o-…']`. Slugs are what's stored in `feedback_posts.modules`; don't rename them once posts exist. Empty hides the module field. |
| `moduleFromRoute(Closure)` | none | Gets `$route` (route name or null) and `$url`; returns a module slug or null. Pre-selects the page's module, and lends each module the navigation icon of the first page it maps to. |
| `canTriage(bool\|Closure)` | `false` | Who opens the inbox and replies as the team. Nobody until set. |
| `button(bool)` | `true` | Floating button + My feedback page. Only renders for logged-in users. |
| `inbox(bool)` | `true` | The inbox page (still gated by `canTriage`). |
| `chatWebhook(?string)` | none | Google Chat space webhook; one line per new post. Null/empty turns it off. |
| `developerPosts(bool $enabled = true, bool\|Closure\|null $canRead = null, ?string $chatWebhook = null)` | on, all triagers read | The "For the developers" kind. `canRead` limits who sees those posts in the inbox; `chatWebhook` pings a separate space. |
| `allowUnsend(team: bool\|Closure, authors: bool\|Closure)` | team `true`, authors `false` | Who may unsend their own replies. Set on the panel each side works from. |
| `notifyAuthorUsing(Closure)` | Filament database notification | Replaces the "team replied" notification. Gets `$post`, `$reply`, `$author`, `$url`. |
| `launcherAttributes(array)` | `[]` | Extra HTML attributes on the button wrapper. |
| `impersonateUsing(?Closure $callback, bool\|Closure $can = true)` | off | "View as" on an inbox thread: sign in as the author and open the page they wrote from. The callback does the impersonating with the app's own package (no dependency here), gets `$author`, `$post`, `$url` (same host only, else null) and `$guard`, and returns a redirect, or false. `$can` gets `$author`; triagers only. |
| `navigationGroup(?string)` | none | Inbox navigation group. |

### Common tasks

- **Notify triagers of new posts:** listen to `CarlJanzell\FilamentFeedback\Events\FeedbackPosted` (`$event->post`).
- **React to replies:** listen to `CarlJanzell\FilamentFeedback\Events\ReplyPosted` (`$event->reply`; `$event->reply->is_from_team` tells the side).
- **Author reply notifications:** call `->databaseNotifications()` on the button's panel, and the user model must use `Notifiable`. Otherwise use `notifyAuthorUsing()`.
- **Open the sheet from a link or button:** link to `#feedback` on any page of the button's panel.
- **Move the button:** CSS on `:root`: `--ffb-launcher-bottom`, `--ffb-launcher-left`.
- **View as the author (impersonate):** `impersonateUsing()` with the app's impersonation package, e.g. stechstudio/filament-impersonate: `fn (Model $author, ?string $url, string $guard) => Impersonate::make()->guard($guard)->redirectTo($url ?? '/')->impersonate($author)`. Don't add an impersonation dependency to the package.
- **Stats on a dashboard:** add `CarlJanzell\FilamentFeedback\Widgets\FeedbackStats` to the panel's widgets. On the inbox only triagers see it; elsewhere the host page decides.
- **Link to a thread or the inbox from outside the app:** `FilamentFeedbackPlugin::threadUrl($post->panel, $post->id)`, `FilamentFeedbackPlugin::inboxUrl()`.
- **Read the plugin's config at runtime:** `FilamentFeedbackPlugin::get()` (current panel).

### Data

Tables `feedback_posts`, `feedback_replies`, `feedback_reactions`, created by the package's own migrations (loaded automatically, nothing to publish). Authors are polymorphic (`author_type`/`author_id`), so any authenticatable model works, UUID keys included.

@verbatim
<code-snippet name="Querying feedback" lang="php">
use CarlJanzell\FilamentFeedback\Enums\FeedbackKind;
use CarlJanzell\FilamentFeedback\Enums\FeedbackStatus;
use CarlJanzell\FilamentFeedback\Models\FeedbackPost;

FeedbackPost::query()->ownedBy($user)->latest()->get();             // one person's posts
FeedbackPost::query()->where('status', FeedbackStatus::New)->count();
FeedbackPost::query()->where('kind', FeedbackKind::Problem)->whereJsonContains('modules', 'dtr')->get();

$post->addReply($teamMember, 'Fixed in 2.3.', fromTeam: true);     // fires ReplyPosted
$post->update(['status' => FeedbackStatus::Done]);                 // logged to status_log automatically
</code-snippet>
@endverbatim

- Enums: `FeedbackKind` (`feedback|question|problem|developers`), `FeedbackStatus` (`new|seen|planned|done`), `FeedbackMood` (int `-2..2`, only on `feedback` posts), `Reaction` (`thanks|same|seen|fixed`).
- `FeedbackPost` columns worth knowing: `panel` (panel id it was written on), `modules` (JSON slugs or null), `context` (JSON: `url`, `route`, `user_agent`, `app_version` from `config('app.version')`), `status_log`, `fix_confirmed`.

### Don't

- Don't build a Filament resource, form or page to collect feedback: the button and sheet already do it on every page.
- Don't write to `status_log` by hand; the model appends on every status change.
- Don't expect the inbox to show for everyone: it's hidden until `canTriage()` returns true for the user.
- Don't add the package's views to the app's Tailwind `content`/`@source`; it ships its own CSS.
