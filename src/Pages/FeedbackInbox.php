<?php

namespace CarlJanzell\FilamentFeedback\Pages;

use CarlJanzell\FilamentFeedback\Actions\ImportSuggestions;
use CarlJanzell\FilamentFeedback\Actions\RespondToPost;
use CarlJanzell\FilamentFeedback\Actions\SendSuggestion;
use CarlJanzell\FilamentFeedback\Enums\FeedbackKind;
use CarlJanzell\FilamentFeedback\Enums\FeedbackMood;
use CarlJanzell\FilamentFeedback\Enums\FeedbackStatus;
use CarlJanzell\FilamentFeedback\Enums\Reaction;
use CarlJanzell\FilamentFeedback\Enums\SuggestionConfidence;
use CarlJanzell\FilamentFeedback\FilamentFeedbackPlugin;
use CarlJanzell\FilamentFeedback\Models\FeedbackPost;
use CarlJanzell\FilamentFeedback\Models\FeedbackReply;
use CarlJanzell\FilamentFeedback\Models\FeedbackSuggestion;
use CarlJanzell\FilamentFeedback\Support\Compat;
use CarlJanzell\FilamentFeedback\Widgets\FeedbackStats;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\IconPosition;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * Where triagers read every post, reply and set a status. Registered by the plugin on panels
 * with `->inbox()` and open only to people `->canTriage()` lets in.
 */
class FeedbackInbox extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'feedback';

    /** @var array{send: int, low: int, stale: int}|null worked out once per request */
    protected ?array $suggestionCounts = null;

    public static function canAccess(): bool
    {
        return FilamentFeedbackPlugin::get()->isTriager();
    }

    /**
     * Shares one blade view across Filament 3–5 (3 declares `$view` static, 4 and 5 don't).
     */
    public function getView(): string
    {
        return 'filament-feedback::inbox';
    }

    public function getTitle(): string
    {
        return 'Feedback';
    }

    public static function getNavigationLabel(): string
    {
        return 'Feedback';
    }

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-chat-bubble-left-right';
    }

    public static function getNavigationGroup(): ?string
    {
        return FilamentFeedbackPlugin::get()->getNavigationGroup();
    }

    public static function getNavigationBadge(): ?string
    {
        $new = FeedbackPost::query()->visibleToTeam()->where('status', FeedbackStatus::New)->count();

        return $new > 0 ? (string) $new : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public function table(Table $table): Table
    {
        $modules = FilamentFeedbackPlugin::get()->getModules();
        $icons = FilamentFeedbackPlugin::get()->getModuleIcons();
        $suggestions = FilamentFeedbackPlugin::get()->hasSuggestions();

        return $table
            ->query(FeedbackPost::query()->visibleToTeam()->with('author')->withCount('replies')
                ->when($suggestions, fn (Builder $query) => $query->with('pendingSuggestion')->withMax('replies', 'id')))
            ->columns([
                TextColumn::make('created_at')
                    ->label('Sent')
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
                TextColumn::make('author_name')
                    ->label('From')
                    ->state(fn (FeedbackPost $record): string => $record->authorName())
                    ->weight(FontWeight::SemiBold)
                    ->description(fn (FeedbackPost $record): string => $record->kind->shortLabel()),
                TextColumn::make('mood')
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('modules')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state): string => $modules[$state] ?? $state)
                    ->icon(fn (?string $state) => $icons[$state ?? ''] ?? null)
                    ->placeholder('—'),
                TextColumn::make('body')
                    ->label('Message')
                    ->limit(110)
                    ->wrap()
                    ->searchable(),
                // A sparkles icon after the count marks a suggested reply: bright when "Send suggested"
                // would send it, grey when a person has to open it first. No column of its own: the
                // message is the column that needs the room.
                TextColumn::make('replies_count')
                    ->label('Replies')
                    ->numeric()
                    ->alignCenter()
                    ->icon(fn (FeedbackPost $record): ?string => $suggestions && $record->pendingSuggestion ? 'heroicon-m-sparkles' : null)
                    ->iconPosition(IconPosition::After)
                    ->iconColor(fn (FeedbackPost $record): string => $suggestions && $this->suggestionOf($record)?->isSafeToSend() ? 'primary' : 'gray')
                    ->tooltip(fn (FeedbackPost $record): ?string => $suggestions ? $this->suggestionTooltip($this->suggestionOf($record)) : null),
                TextColumn::make('status')
                    ->badge(),
            ])
            ->filters([
                SelectFilter::make('status')->options(FeedbackStatus::class),
                SelectFilter::make('kind')->label('Type')->options(collect(FilamentFeedbackPlugin::get()->getKinds())->filter(fn (FeedbackKind $kind): bool => $kind !== FeedbackKind::Developers || FilamentFeedbackPlugin::get()->canReadDeveloperPosts())->mapWithKeys(fn (FeedbackKind $kind): array => [$kind->value => $kind->shortLabel()])->all()),
                SelectFilter::make('mood')->options(FeedbackMood::class),
                SelectFilter::make('module')
                    ->options($modules)
                    ->visible($modules !== [])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereJsonContains('modules', $data['value'])
                        : $query),
                TernaryFilter::make('suggestion')
                    ->label('Suggestion')
                    ->placeholder('All')
                    ->trueLabel('Has one')
                    ->falseLabel('None')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereHas('suggestions', fn (Builder $suggestions) => $suggestions->pending()),
                        false: fn (Builder $query): Builder => $query->whereDoesntHave('suggestions', fn (Builder $suggestions) => $suggestions->pending()),
                    )
                    ->visible($suggestions),
            ], layout: FiltersLayout::AboveContent)
            ->filtersFormColumns($suggestions ? 5 : 4)
            ->actions([
                Compat::tableAction('open')
                    ->label('Open')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->slideOver()
                    ->modalWidth('xl')
                    ->modalHeading(fn (FeedbackPost $record): string => $record->kind->shortLabel().' from '.$record->authorName())
                    ->modalContent(fn (FeedbackPost $record) => view('filament-feedback::thread', [
                        'post' => $record->load(['reactions', 'replies.author', 'replies.reactions']),
                        'modules' => $modules,
                        'moduleIcons' => $icons,
                        'viewer' => Filament::auth()->user(),
                        'canUnsend' => FilamentFeedbackPlugin::get()->teamCanUnsend(),
                        'canImpersonate' => FilamentFeedbackPlugin::get()->canImpersonate($record->author),
                        // Fresh, not the row's eager load: Discard suggestion re-renders this.
                        'suggestion' => $suggestions ? $record->pendingSuggestion()->first()?->setRelation('post', $record) : null,
                    ]))
                    ->fillForm(function (FeedbackPost $record) use ($suggestions): array {
                        // Opening a new post is reading it.
                        if ($record->status === FeedbackStatus::New) {
                            $record->update(['status' => FeedbackStatus::Seen]);
                        }

                        $suggestion = $suggestions ? $record->pendingSuggestion : null;

                        return [
                            'suggestion' => $suggestion?->getKey(),
                            'reply' => $suggestion?->body,
                            'status' => ($suggestion?->status ?? $record->status)->value,
                        ];
                    })
                    ->form(fn (FeedbackPost $record): array => [
                        // Which suggestion the box was filled from, so Save records how it went out.
                        Hidden::make('suggestion'),
                        Textarea::make('reply')
                            ->label('Reply')
                            ->helperText('The author sees this on My feedback and gets a notification.')
                            ->rows(4)
                            ->maxLength(ImportSuggestions::MAX_REPLY_LENGTH)
                            ->hintAction(Compat::formAction('discardSuggestion')
                                ->label('Discard suggestion')
                                ->icon('heroicon-m-x-mark')
                                ->color('gray')
                                ->visible(fn ($get): bool => filled($get('suggestion')))
                                ->action(function ($get, $set) use ($record): void {
                                    $record->suggestions()->pending()->find($get('suggestion'))?->discard(Filament::auth()->user());
                                    $set('suggestion', null);
                                    $set('reply', null);
                                    $set('status', $record->status->value);
                                })),
                        ToggleButtons::make('status')
                            ->options(FeedbackStatus::class)
                            ->inline()
                            ->required(),
                    ])
                    ->modalSubmitActionLabel('Save')
                    ->action(fn (array $data, FeedbackPost $record) => $this->respond($record, $data)),
            ])
            ->bulkActions($suggestions ? [
                Compat::bulkAction('sendSuggestions')
                    ->label('Send suggestions')
                    ->icon('heroicon-m-paper-airplane')
                    ->requiresConfirmation()
                    ->modalHeading('Send the suggested replies?')
                    ->modalDescription('Each selected post that has a suggestion gets it, sent as you, low-confidence ones too. A suggestion whose thread changed after it was drafted is skipped: open that post instead.')
                    ->modalSubmitActionLabel('Send')
                    ->action(fn (Collection $records) => $this->sendDrafts(
                        $this->pendingSuggestions()->whereIn('feedback_post_id', $records->modelKeys()),
                        includeLow: true,
                    ))
                    ->deselectRecordsAfterCompletion(),
            ] : [])
            ->recordAction('open')
            ->defaultSort('created_at', 'desc')
            ->striped()
            ->poll('30s')
            ->emptyStateIcon('heroicon-o-chat-bubble-left-right')
            ->emptyStateHeading('No feedback yet')
            ->emptyStateDescription('Posts from the Feedback button land here.');
    }

    /**
     * Save in the Open slide-over. A box filled from a suggestion sends that suggestion, as is or
     * edited, so it records how it went out.
     *
     * @param  array{reply?: string|null, status: FeedbackStatus|string, suggestion?: int|string|null}  $data
     */
    public function respond(FeedbackPost $post, array $data): void
    {
        $user = Filament::auth()->user();
        // Filament 4+ hands enum options back as enums, Filament 3 as their values.
        $status = $data['status'] instanceof FeedbackStatus ? $data['status'] : FeedbackStatus::from($data['status']);
        $suggestion = filled($data['suggestion'] ?? null) && FilamentFeedbackPlugin::get()->hasSuggestions()
            ? $post->suggestions()->pending()->find($data['suggestion'])
            : null;

        try {
            $reply = $suggestion
                ? app(SendSuggestion::class)->execute($suggestion, $user, (string) ($data['reply'] ?? ''), $status)
                : app(RespondToPost::class)->execute($post, $user, $data['reply'] ?? null, $status);
        } catch (LogicException) {
            Notification::make()->title('Someone else already sent or discarded this suggestion')->body('Nothing was sent. Open the post again to see where it stands.')->warning()->send();

            return;
        }

        Notification::make()->title($reply ? 'Reply sent' : 'Saved')->success()->send();
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        if (! FilamentFeedbackPlugin::get()->hasSuggestions()) {
            return [];
        }

        return [
            Action::make('sendSuggested')
                ->label(fn (): string => 'Send suggested ('.$this->suggestionCounts()['send'].')')
                ->icon('heroicon-m-paper-airplane')
                ->visible(fn (): bool => $this->suggestionCounts()['send'] > 0)
                ->requiresConfirmation()
                ->modalIcon('heroicon-o-paper-airplane')
                ->modalHeading(fn (): string => 'Send '.$this->suggestionCounts()['send'].' suggested '.Str::plural('reply', $this->suggestionCounts()['send']).'?')
                ->modalDescription(fn (): string => $this->sendAllDescription())
                ->modalSubmitActionLabel('Send')
                ->action(fn () => $this->sendDrafts($this->pendingSuggestions(), includeLow: false)),
            Action::make('importSuggestions')
                ->label('Import suggestions')
                ->icon('heroicon-m-arrow-down-tray')
                ->color('gray')
                ->modalHeading('Import suggested replies')
                ->modalDescription('Paste the suggestions JSON. Nothing reaches anyone: each one waits on its post until a triager sends it. A new suggestion for a post replaces the older one.')
                ->form([
                    Textarea::make('json')
                        ->label('Suggestions (JSON)')
                        ->placeholder('{"version": 1, "suggestions": [{"post_id": 12, "reply": "…", "status": "done", "confidence": "high"}]}')
                        ->rows(12)
                        ->required(),
                    Toggle::make('dry_run')
                        ->label('Dry run')
                        ->helperText('Check the JSON and show what would happen, without saving.')
                        ->default(true),
                ])
                ->modalSubmitActionLabel('Import')
                ->action(function (array $data, $action): void {
                    $this->importJson($data['json'], (bool) $data['dry_run']);

                    // Keep the JSON on screen after a dry run, so turning it off and importing is one step.
                    if ($data['dry_run']) {
                        $action->halt();
                    }
                }),
        ];
    }

    protected function importJson(string $json, bool $dryRun): void
    {
        $payload = json_decode($json, true);

        try {
            throw_unless(is_array($payload), InvalidArgumentException::class, 'Not valid JSON: '.json_last_error_msg().'.');
            $report = app(ImportSuggestions::class)->execute($payload, $dryRun);
        } catch (InvalidArgumentException $exception) {
            Notification::make()->title('Nothing imported')->body($exception->getMessage())->danger()->send();

            return;
        }

        $this->suggestionCounts = null;
        $skipped = collect($report['skipped'])
            ->map(fn (array $skip): string => 'Post '.(is_scalar($skip['post_id']) ? $skip['post_id'] : '?').': '.$skip['reason'])
            ->implode("\n");

        Notification::make()
            ->title(($dryRun ? 'Dry run: would add ' : 'Added ').$report['created'].' '.Str::plural('suggestion', $report['created'])
                .($report['superseded'] ? ', replacing '.$report['superseded'] : '')
                .($report['skipped'] ? ', skipping '.count($report['skipped']) : ''))
            ->body($skipped !== '' ? $skipped : ($dryRun ? 'Turn off Dry run to import.' : null))
            ->status($report['skipped'] ? 'warning' : 'success')
            ->persistent()
            ->send();
    }

    /**
     * Pending suggestions on posts this triager may read, with what isStale() needs loaded.
     *
     * @return Builder<FeedbackSuggestion>
     */
    protected function pendingSuggestions(): Builder
    {
        return FeedbackSuggestion::query()
            ->pending()
            ->whereHas('post', fn (Builder $posts) => $posts->visibleToTeam())
            ->with(['post' => fn ($posts) => $posts->withMax('replies', 'id')]);
    }

    /**
     * What "Send suggested" would do now: how many go out, and how many it skips and why.
     *
     * @return array{send: int, low: int, stale: int}
     */
    protected function suggestionCounts(): array
    {
        return $this->suggestionCounts ??= $this->pendingSuggestions()->get()->reduce(function (array $counts, FeedbackSuggestion $suggestion): array {
            $counts[match (true) {
                $suggestion->isStale() => 'stale',
                $suggestion->confidence === SuggestionConfidence::Low => 'low',
                default => 'send',
            }]++;

            return $counts;
        }, ['send' => 0, 'low' => 0, 'stale' => 0]);
    }

    protected function sendAllDescription(): string
    {
        $counts = $this->suggestionCounts();
        $held = array_filter([
            $counts['low'] ? $counts['low'].' low-confidence' : null,
            $counts['stale'] ? $counts['stale'].' where the thread changed after drafting' : null,
        ]);

        return 'Replies go out as you, and each author is notified.'
            .($held ? ' Skipped, for you to open first: '.implode(' and ', $held).'.' : '');
    }

    /**
     * Sends each suggestion as the current triager, one at a time, so one failure doesn't stop the rest.
     *
     * @param  Builder<FeedbackSuggestion>  $suggestions
     */
    protected function sendDrafts(Builder $suggestions, bool $includeLow): void
    {
        $user = Filament::auth()->user();
        $sent = 0;
        $skipped = ['low confidence' => 0, 'thread changed' => 0, 'failed' => 0];

        foreach ($suggestions->get() as $suggestion) {
            if ($suggestion->isStale()) {
                $skipped['thread changed']++;

                continue;
            }

            if (! $includeLow && $suggestion->confidence === SuggestionConfidence::Low) {
                $skipped['low confidence']++;

                continue;
            }

            try {
                app(SendSuggestion::class)->execute($suggestion, $user);
                $sent++;
            } catch (Throwable $exception) {
                report($exception);
                $skipped['failed']++;
            }
        }

        $this->suggestionCounts = null;
        $skippedCount = array_sum($skipped);
        $reasons = collect($skipped)->filter()->map(fn (int $count, string $reason): string => "{$count} {$reason}")->implode(', ');

        Notification::make()
            ->title("Sent {$sent}".($skippedCount ? " · skipped {$skippedCount} ({$reasons})" : ''))
            ->status($skipped['failed'] ? 'warning' : 'success')
            ->send();
    }

    /**
     * The row's pending suggestion, with its post set so isStale() reads the row's loaded max reply id.
     */
    protected function suggestionOf(FeedbackPost $record): ?FeedbackSuggestion
    {
        return $record->pendingSuggestion?->setRelation('post', $record);
    }

    protected function suggestionTooltip(?FeedbackSuggestion $suggestion): ?string
    {
        if ($suggestion === null) {
            return null;
        }

        return 'Suggested reply waiting · '.$suggestion->confidence->getLabel()
            .($suggestion->isStale() ? ' · thread changed since' : '');
    }

    /**
     * A triager's reaction on a post or reply, from the thread in the Open slide-over.
     *
     * @param  'post'|'reply'  $type
     */
    public function react(string $type, int $id, string $reaction): void
    {
        abort_unless(FilamentFeedbackPlugin::get()->isTriager(), 403);

        $target = $type === 'post'
            ? FeedbackPost::query()->visibleToTeam()->find($id)
            : FeedbackReply::query()->whereHas('post', fn ($posts) => $posts->visibleToTeam())->find($id);
        $user = Filament::auth()->user();
        abort_if($target === null || $target->isWrittenBy($user), 404);

        $target->toggleReaction($user, Reaction::from($reaction));
    }

    public function unsend(int $replyId): void
    {
        $reply = FeedbackReply::query()->find($replyId);
        abort_unless($reply && $reply->is_from_team && $reply->isWrittenBy(Filament::auth()->user()) && FilamentFeedbackPlugin::get()->isTriager() && FilamentFeedbackPlugin::get()->teamCanUnsend(), 403);

        $reply->unsend();
    }

    /**
     * "View as": hands the author and the page they wrote from to the app's impersonateUsing().
     */
    public function impersonate(int $postId): mixed
    {
        $plugin = FilamentFeedbackPlugin::get();
        $post = FeedbackPost::query()->visibleToTeam()->with('author')->find($postId);
        abort_unless($post && $plugin->canImpersonate($post->author), 403);

        // Only a page on this app: the URL comes from the author's browser.
        $url = $post->context['url'] ?? null;
        $url = is_string($url) && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true) && parse_url($url, PHP_URL_HOST) === request()->getHost() ? $url : null;

        // And one that still exists: a page renamed or removed since would land on a 404.
        try {
            $url && app('router')->getRoutes()->match(Request::create($url));
        } catch (Throwable) {
            $url = null;
        }

        $result = $plugin->evaluate($plugin->getImpersonateUsing(), [
            'author' => $post->author,
            'post' => $post,
            'url' => $url,
            'guard' => (Filament::getPanels()[$post->panel] ?? Filament::getCurrentPanel())?->getAuthGuard(),
        ]);

        if (! $result) {
            Notification::make()->title('Couldn\'t sign in as '.$post->authorName())->danger()->send();
        }

        return $result ?: null;
    }

    /**
     * @return array<class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [FeedbackStats::class];
    }

    /**
     * Mood counts per module, busiest module first, over posts of the Feedback kind.
     *
     * @return list<array{slug: string, label: string, counts: array<int, int>, total: int, average: float}>
     */
    public function getBoard(): array
    {
        $modules = FilamentFeedbackPlugin::get()->getModules();

        // ponytail: tallies in PHP; fine to tens of thousands of posts, move to a JSON_TABLE / jsonb query past that.
        $rows = [];

        FeedbackPost::query()
            ->where('kind', FeedbackKind::Feedback)
            ->whereNotNull('mood')
            ->select(['id', 'modules', 'mood'])
            ->lazyById()
            ->each(function (FeedbackPost $post) use (&$rows, $modules): void {
                foreach ($post->modules ?: ['_none'] as $slug) {
                    $rows[$slug] ??= [
                        'slug' => $slug,
                        'label' => $slug === '_none' ? 'No module' : ($modules[$slug] ?? $slug),
                        'counts' => array_fill_keys(array_map(fn (FeedbackMood $mood): int => $mood->value, FeedbackMood::cases()), 0),
                        'total' => 0,
                        'sum' => 0,
                    ];
                    $rows[$slug]['counts'][$post->mood->value]++;
                    $rows[$slug]['total']++;
                    $rows[$slug]['sum'] += $post->mood->value;
                }
            });

        return collect($rows)
            ->map(fn (array $row): array => [...$row, 'average' => round($row['sum'] / $row['total'], 1)])
            ->sortByDesc('total')
            ->values()
            ->all();
    }
}
