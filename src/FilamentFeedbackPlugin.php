<?php

namespace CarlJanzell\FilamentFeedback;

use BackedEnum;
use CarlJanzell\FilamentFeedback\Enums\FeedbackKind;
use CarlJanzell\FilamentFeedback\Pages\FeedbackInbox;
use CarlJanzell\FilamentFeedback\Pages\MyFeedback;
use Closure;
use Filament\Contracts\Plugin;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Resources\Pages\Page as ResourcePage;
use Filament\Support\Concerns\EvaluatesClosures;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Throwable;

/**
 * Register it on every panel that should show the feedback button or the inbox:
 *
 *     FilamentFeedbackPlugin::make()
 *         ->modules(['dtr' => 'DTR', 'leave' => 'Leave'])
 *         ->canTriage(fn (): bool => auth()->user()?->isAdmin() ?? false)
 */
class FilamentFeedbackPlugin implements Plugin
{
    use EvaluatesClosures;

    /** @var array<string, string|array{label: string, icon?: string|BackedEnum|Htmlable|null}>|Closure */
    protected array|Closure $modules = [];

    /** @var array<string, string|BackedEnum|Htmlable>|null slug => icon, worked out once per request */
    protected ?array $moduleIcons = null;

    protected ?string $panelId = null;

    protected ?Closure $moduleFromRoute = null;

    protected bool|Closure $canTriage = false;

    protected bool|Closure $hasButton = true;

    protected bool $hasInbox = true;

    /** @var array<string, string> */
    protected array $launcherAttributes = [];

    protected ?string $chatWebhook = null;

    protected ?string $navigationGroup = null;

    protected ?Closure $notifyAuthorUsing = null;

    protected ?Closure $impersonateUsing = null;

    protected bool|Closure $canImpersonate = true;

    protected bool|Closure $teamCanUnsend = true;

    protected bool|Closure $authorsCanUnsend = false;

    protected bool $hasDeveloperPosts = true;

    protected bool|Closure|null $canReadDeveloperPosts = null;

    protected ?string $developerChatWebhook = null;

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        /** @var static $plugin */
        $plugin = filament(app(static::class)->getId());

        return $plugin;
    }

    public function getId(): string
    {
        return 'feedback';
    }

    public function register(Panel $panel): void
    {
        $this->panelId = $panel->getId();

        if ($this->hasInbox) {
            $panel->pages([FeedbackInbox::class]);
        }

        // Posts are written on the button's panel, so their threads live there too.
        if ($this->hasButton()) {
            $panel->pages([MyFeedback::class]);
        }

        if ($this->hasButton()) {
            $panel->renderHook('panels::body.end', fn (): string => Filament::auth()->check() && $this->showsButton()
                ? Blade::render('@livewire(\'filament-feedback-launcher\', [\'panel\' => $panel])', ['panel' => $panel->getId()])
                : '');
        }
    }

    public function boot(Panel $panel): void {}

    /**
     * The parts of the app people can tag. Slugs are what's stored. Each one is a label, or
     * `['label' => …, 'icon' => …]`. Without an icon, a module borrows the navigation icon of
     * the page `moduleFromRoute()` maps to it, so DTR looks the same in the sidebar and here.
     *
     * @param  array<string, string|array{label: string, icon?: string|BackedEnum|Htmlable|null}>|Closure  $modules
     */
    public function modules(array|Closure $modules): static
    {
        $this->modules = $modules;
        $this->moduleIcons = null;

        return $this;
    }

    /**
     * @return array<string, string> slug => label
     */
    public function getModules(): array
    {
        return array_map(
            fn (string|array $module): string => is_array($module) ? $module['label'] : $module,
            $this->evaluate($this->modules),
        );
    }

    /**
     * @return array<string, string|BackedEnum|Htmlable> slug => icon, for modules that have one
     */
    public function getModuleIcons(): array
    {
        if ($this->moduleIcons !== null) {
            return $this->moduleIcons;
        }

        $given = array_filter(array_map(
            fn (string|array $module): string|BackedEnum|Htmlable|null => is_array($module) ? ($module['icon'] ?? null) : null,
            $this->evaluate($this->modules),
        ));

        return $this->moduleIcons = [...$this->navigationIcons(), ...$given];
    }

    /**
     * Module slugs named with # in a message: `#DTR`, `#dtr`, `#BreakRoom` and `#break-room` all
     * name the same module (slug or label, case and punctuation ignored).
     *
     * @return list<string>
     */
    public function modulesMentionedIn(?string $body): array
    {
        preg_match_all('/(?<![\w&])#([\pL\pN][\pL\pN_-]*)/u', (string) $body, $matches);

        $tokens = $this->mentionTokens();

        return array_values(array_unique(array_filter(array_map(
            fn (string $token): ?string => $tokens[static::mentionKey($token)] ?? null,
            $matches[1],
        ))));
    }

    /**
     * @return array<string, string> normalized slug or label => slug
     */
    public function mentionTokens(): array
    {
        $tokens = [];

        foreach ($this->getModules() as $slug => $label) {
            $tokens[static::mentionKey($label)] ??= $slug;
            $tokens[static::mentionKey($slug)] = $slug;
        }

        return $tokens;
    }

    /**
     * What a module is written as after #, e.g. "Break Room" → "BreakRoom".
     */
    public static function mentionText(string $label): string
    {
        return Str::studly(preg_replace('/[^\pL\pN]+/u', ' ', $label));
    }

    protected static function mentionKey(string $text): string
    {
        return Str::lower(preg_replace('/[^\pL\pN]+/u', '', $text));
    }

    /**
     * slug => icon from the panel's own pages and resources, read off its GET routes (no
     * parameters) so it works the same on Filament 3, 4 and 5. The first page per module wins.
     *
     * @return array<string, string|BackedEnum|Htmlable>
     */
    protected function navigationIcons(): array
    {
        if ($this->moduleFromRoute === null || $this->panelId === null) {
            return [];
        }

        $modules = $this->getModules();
        $icons = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $name = $route->getName();

            if (! Str::startsWith((string) $name, "filament.{$this->panelId}.") || str_contains($route->uri(), '{') || ! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $class = Str::before($route->getActionName(), '@');

            try {
                $icon = match (true) {
                    is_subclass_of($class, ResourcePage::class) => $class::getResource()::getNavigationIcon(),
                    is_subclass_of($class, Page::class) => $class::getNavigationIcon(),
                    default => null,
                };
                $slug = $icon ? $this->resolveModule($name, '/'.$route->uri()) : null;
            } catch (Throwable) {
                continue;
            }

            if ($slug !== null && isset($modules[$slug]) && ! isset($icons[$slug])) {
                $icons[$slug] = $icon;
            }
        }

        return $icons;
    }

    /**
     * Pre-selects the module of the page the person is on. Receives `$route` (the route
     * name, or null) and `$url`; returns a module slug or null.
     */
    public function moduleFromRoute(?Closure $resolver): static
    {
        $this->moduleFromRoute = $resolver;

        return $this;
    }

    public function resolveModule(?string $route, string $url): ?string
    {
        if ($this->moduleFromRoute === null) {
            return null;
        }

        $slug = $this->evaluate($this->moduleFromRoute, ['route' => $route, 'url' => $url]);

        return array_key_exists((string) $slug, $this->getModules()) ? $slug : null;
    }

    /**
     * Who reads every post and replies as the team. Nobody, until you say so.
     */
    public function canTriage(bool|Closure $condition): static
    {
        $this->canTriage = $condition;

        return $this;
    }

    public function isTriager(): bool
    {
        return (bool) $this->evaluate($this->canTriage);
    }

    /**
     * The floating button people post from. Turn it off on admin-only panels. A closure is
     * checked on every page (My feedback stays), e.g. to hide it while impersonating someone.
     */
    public function button(bool|Closure $condition = true): static
    {
        $this->hasButton = $condition;

        return $this;
    }

    /**
     * The inbox page triagers work from. Turn it off on panels everyone uses.
     */
    public function inbox(bool $condition = true): static
    {
        $this->hasInbox = $condition;

        return $this;
    }

    public function hasInbox(): bool
    {
        return $this->hasInbox;
    }

    /**
     * Extra HTML attributes on the floating button's wrapper, so the app's own CSS can
     * move it, e.g. above a phone tab bar.
     *
     * @param  array<string, string>  $attributes
     */
    public function launcherAttributes(array $attributes): static
    {
        $this->launcherAttributes = $attributes;

        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function getLauncherAttributes(): array
    {
        return $this->launcherAttributes;
    }

    /**
     * A Google Chat space webhook URL; every new post sends it one line. Null turns it off.
     */
    public function chatWebhook(?string $url): static
    {
        $this->chatWebhook = filled($url) ? $url : null;

        return $this;
    }

    public function getChatWebhook(?FeedbackKind $kind = null): ?string
    {
        return $kind === FeedbackKind::Developers && $this->developerChatWebhook
            ? $this->developerChatWebhook
            : $this->chatWebhook;
    }

    /**
     * Replaces how the author is told about a team reply. Receives `$post`, `$reply`,
     * `$author` and `$url` (the author's panel, ending in #feedback, or null). For apps
     * whose notification table is managed by another package.
     */
    public function notifyAuthorUsing(?Closure $callback): static
    {
        $this->notifyAuthorUsing = $callback;

        return $this;
    }

    public function getNotifyAuthorUsing(): ?Closure
    {
        return $this->notifyAuthorUsing;
    }

    /**
     * Adds "View as" to a thread in the inbox: sign in as the author and open the page they
     * wrote from. The package impersonates nobody itself; `$callback` does it with the app's
     * own impersonation package, gets `$author`, `$post`, `$url` (the page it was sent from,
     * same host only, else null) and `$guard` (the author's panel guard), and returns a
     * redirect, or false when it refused. `$can` gets `$author`; triagers only either way.
     */
    public function impersonateUsing(?Closure $callback, bool|Closure $can = true): static
    {
        $this->impersonateUsing = $callback;
        $this->canImpersonate = $can;

        return $this;
    }

    public function getImpersonateUsing(): ?Closure
    {
        return $this->impersonateUsing;
    }

    public function canImpersonate(?Model $author): bool
    {
        return $this->impersonateUsing !== null
            && $author !== null
            && $this->isTriager()
            && ! $author->is(Filament::auth()->user())
            && (bool) $this->evaluate($this->canImpersonate, ['author' => $author]);
    }

    /**
     * Who may take back (unsend) their own replies: the team in the inbox, authors on My
     * feedback. An unsent reply stays in the thread as "unsent a message". Set it on each
     * panel the side works from (the inbox's panel for the team, the button's for authors).
     */
    public function allowUnsend(bool|Closure $team = true, bool|Closure $authors = false): static
    {
        $this->teamCanUnsend = $team;
        $this->authorsCanUnsend = $authors;

        return $this;
    }

    public function teamCanUnsend(): bool
    {
        return (bool) $this->evaluate($this->teamCanUnsend);
    }

    public function authorsCanUnsend(): bool
    {
        return (bool) $this->evaluate($this->authorsCanUnsend);
    }

    /**
     * The "For the developers" box in the sheet: messages meant for the dev team, not the
     * people who handle feedback. `$canRead` limits who sees them in the inbox; by default,
     * every triager. `$chatWebhook` pings the dev team's own Chat space instead of
     * `chatWebhook()`. Turn the box off with `developerPosts(false)`.
     */
    public function developerPosts(bool $enabled = true, bool|Closure|null $canRead = null, ?string $chatWebhook = null): static
    {
        $this->hasDeveloperPosts = $enabled;
        $this->canReadDeveloperPosts = $canRead;
        $this->developerChatWebhook = filled($chatWebhook) ? $chatWebhook : null;

        return $this;
    }

    public function hasDeveloperPosts(): bool
    {
        return $this->hasDeveloperPosts;
    }

    public function canReadDeveloperPosts(): bool
    {
        return $this->canReadDeveloperPosts === null
            ? $this->isTriager()
            : (bool) $this->evaluate($this->canReadDeveloperPosts);
    }

    /**
     * The kinds offered in the sheet, in order.
     *
     * @return list<FeedbackKind>
     */
    public function getKinds(): array
    {
        return array_values(array_filter(
            FeedbackKind::cases(),
            fn (FeedbackKind $kind): bool => $kind !== FeedbackKind::Developers || $this->hasDeveloperPosts,
        ));
    }

    public function navigationGroup(?string $group): static
    {
        $this->navigationGroup = $group;

        return $this;
    }

    public function getNavigationGroup(): ?string
    {
        return $this->navigationGroup;
    }

    public function hasButton(): bool
    {
        return $this->hasButton !== false;
    }

    /** Whether the button shows for the current user, right now. */
    public function showsButton(): bool
    {
        return (bool) $this->evaluate($this->hasButton);
    }

    /**
     * A thread on the author's side: My feedback on the panel it was written on.
     */
    public static function threadUrl(?string $panelId, int|string|null $postId = null): ?string
    {
        $panel = Filament::getPanels()[$panelId ?? ''] ?? null;

        if (! $panel?->hasPlugin('feedback') || ! $panel->getPlugin('feedback')->hasButton()) {
            return $panel?->getUrl();
        }

        return MyFeedback::getUrl($postId ? ['post' => $postId] : [], panel: $panel->getId());
    }

    /**
     * The inbox on the first panel that has one, for links sent outside the app.
     */
    public static function inboxUrl(): ?string
    {
        foreach (Filament::getPanels() as $panel) {
            if ($panel->hasPlugin('feedback') && $panel->getPlugin('feedback')->hasInbox()) {
                return FeedbackInbox::getUrl(panel: $panel->getId());
            }
        }

        return null;
    }
}
