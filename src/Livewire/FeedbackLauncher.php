<?php

namespace CarlJanzell\FilamentFeedback\Livewire;

use CarlJanzell\FilamentFeedback\Enums\FeedbackKind;
use CarlJanzell\FilamentFeedback\Enums\FeedbackMood;
use CarlJanzell\FilamentFeedback\Events\FeedbackPosted;
use CarlJanzell\FilamentFeedback\FilamentFeedbackPlugin;
use CarlJanzell\FilamentFeedback\Models\FeedbackPost;
use CarlJanzell\FilamentFeedback\Support\Compat;
use CarlJanzell\FilamentFeedback\Support\GoogleChatPing;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Components\ViewField;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The floating "Feedback" button and the quick sheet behind it, rendered on every page of a
 * panel by {@see FilamentFeedbackPlugin}. People post here; they follow threads on My feedback.
 */
class FeedbackLauncher extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    #[Locked]
    public string $panel;

    /** The page the button was rendered on, kept for context once Livewire takes over the request. */
    #[Locked]
    public string $pageUrl = '';

    #[Locked]
    public ?string $route = null;

    public function mount(string $panel): void
    {
        $this->panel = $panel;
        $this->pageUrl = request()->fullUrl();
        $this->route = request()->route()?->getName();
    }

    /**
     * The quick sheet: post about this page in a few taps. Threads live on My feedback.
     */
    public function feedbackAction(): Action
    {
        return Action::make('feedback')
            ->label('Feedback')
            ->icon('heroicon-o-chat-bubble-left-right')
            ->badge(fn (): ?int => $this->unreadCount() ?: null)
            ->badgeColor('danger')
            ->extraAttributes(['class' => 'ffb-launcher-button'])
            // CSS anchors this window above the button on wide screens and makes it a bottom sheet on phones.
            ->extraModalWindowAttributes(['class' => 'ffb-sheet'])
            ->modalWidth('md')
            ->modalIcon('heroicon-o-chat-bubble-left-right')
            ->modalHeading('Feedback')
            ->modalDescription('About this page. Only you and the team who reads feedback see it.')
            ->fillForm(fn (): array => [
                'kind' => FeedbackKind::Feedback->value,
                'modules' => array_filter([$this->plugin()->resolveModule($this->route, $this->pageUrl)]),
                'mood' => null,
                'body' => null,
            ])
            ->form(fn (): array => $this->formComponents())
            ->modalContent(fn (): ?View => ($unread = $this->unreadCount()) > 0
                ? view('filament-feedback::sheet-notice', ['unread' => $unread, 'url' => FilamentFeedbackPlugin::threadUrl($this->panel)])
                : null)
            ->modalSubmitActionLabel('Send')
            ->extraModalFooterActions([
                Action::make('myFeedback')
                    ->label('My feedback')
                    ->link()
                    ->color('gray')
                    ->url(FilamentFeedbackPlugin::threadUrl($this->panel)),
            ])
            ->action(fn (array $data) => $this->createPost($data));
    }

    /**
     * @return array<int, \Filament\Forms\Components\Component>
     */
    protected function formComponents(): array
    {
        $modules = $this->plugin()->getModules();

        return [
            ToggleButtons::make('kind')
                ->hiddenLabel()
                ->options(FeedbackKind::class)
                ->inline()
                ->live()
                ->required(),
            // Compact on purpose: the page's module pre-picked, the rest behind a search, and
            // `#Module` in the message adds one too. A wall of chips is too much past a handful.
            ViewField::make('modules')
                ->label('Which parts of the app?')
                ->view('filament-feedback::module-picker')
                ->viewData(['options' => $this->pickerOptions()])
                ->visible($modules !== [])
                ->required(fn ($get): bool => FeedbackKind::fromState($get('kind')) === FeedbackKind::Feedback
                    && $this->plugin()->modulesMentionedIn($get('body')) === []),
            ToggleButtons::make('mood')
                ->label('How do you feel about it?')
                ->options(FeedbackMood::class)
                ->inline()
                ->visible(fn ($get): bool => FeedbackKind::fromState($get('kind')) === FeedbackKind::Feedback)
                ->required(fn ($get): bool => FeedbackKind::fromState($get('kind')) === FeedbackKind::Feedback),
            Textarea::make('body')
                ->placeholder($modules !== [] ? 'Type # to tag a part of the app' : null)
                ->label(fn ($get): string => match (FeedbackKind::fromState($get('kind'))) {
                    FeedbackKind::Question => 'Your question',
                    FeedbackKind::Problem => 'What went wrong, and what were you doing?',
                    default => 'What would you like us to know?',
                })
                ->rows(4)
                ->maxLength(5000)
                ->required(),
        ];
    }

    /**
     * @param  array{kind: FeedbackKind|string, modules?: list<string>|null, mood?: FeedbackMood|int|string|null, body: string}  $data
     */
    public function createPost(array $data): void
    {
        $plugin = $this->plugin();
        $kind = FeedbackKind::fromState($data['kind']);
        $mood = $data['mood'] ?? null;
        $mood = $mood instanceof FeedbackMood || blank($mood) ? $mood : FeedbackMood::from((int) $mood);

        $post = new FeedbackPost([
            'panel' => $this->panel,
            'kind' => $kind,
            'mood' => $kind === FeedbackKind::Feedback ? $mood : null,
            // Only slugs the app offers, whatever the request claims.
            'modules' => array_values(array_unique([
                ...array_intersect((array) ($data['modules'] ?? []), array_keys($plugin->getModules())),
                ...$plugin->modulesMentionedIn($data['body']),
            ])) ?: null,
            'body' => trim($data['body']),
            'context' => array_filter([
                'url' => $this->pageUrl,
                'route' => $this->route,
                'user_agent' => request()->userAgent(),
                'app_version' => config('app.version'),
            ]),
            'author_seen_at' => now(),
        ]);
        $post->author()->associate($this->user());
        $post->save();

        FeedbackPosted::dispatch($post);

        if ($webhook = $plugin->getChatWebhook()) {
            GoogleChatPing::send($webhook, $post, $plugin->getModules());
        }

        $url = FilamentFeedbackPlugin::threadUrl($this->panel, $post->getKey());

        Notification::make()
            ->title('Sent. Thank you!')
            ->body('You\'ll get a notification when the team replies.')
            ->success()
            ->actions($url ? [Compat::notificationAction('follow')->label('Follow it in My feedback')->url($url)] : [])
            ->send();
    }

    /**
     * The picker's list: label, the text it's mentioned as, and its icon pre-rendered.
     *
     * @return list<array{slug: string, label: string, mention: string, icon: string}>
     */
    protected function pickerOptions(): array
    {
        $icons = $this->plugin()->getModuleIcons();

        return collect($this->plugin()->getModules())
            ->map(fn (string $label, string $slug): array => [
                'slug' => $slug,
                'label' => $label,
                'mention' => FilamentFeedbackPlugin::mentionText($label),
                'icon' => isset($icons[$slug])
                    ? Blade::render('<x-filament::icon :icon="$icon" class="ffb-icon" />', ['icon' => $icons[$slug]])
                    : '',
            ])
            ->values()
            ->all();
    }

    public function unreadCount(): int
    {
        return FeedbackPost::query()->ownedBy($this->user())->withUnreadReplies()->count();
    }

    protected function plugin(): FilamentFeedbackPlugin
    {
        /** @var FilamentFeedbackPlugin */
        return Filament::getPanel($this->panel)->getPlugin('feedback');
    }

    protected function user(): Model
    {
        // The panel's own guard: on a Livewire update request no panel is "current".
        /** @var Model */
        return Filament::getPanel($this->panel)->auth()->user();
    }

    public function render(): View
    {
        return view('filament-feedback::launcher', [
            'launcherAttributes' => $this->plugin()->getLauncherAttributes(),
        ]);
    }
}
