<?php

namespace CarlJanzell\FilamentFeedback\Pages;

use CarlJanzell\FilamentFeedback\Enums\FeedbackKind;
use CarlJanzell\FilamentFeedback\Enums\FeedbackMood;
use CarlJanzell\FilamentFeedback\Enums\FeedbackStatus;
use CarlJanzell\FilamentFeedback\Enums\Reaction;
use CarlJanzell\FilamentFeedback\FilamentFeedbackPlugin;
use CarlJanzell\FilamentFeedback\Models\FeedbackPost;
use CarlJanzell\FilamentFeedback\Models\FeedbackReply;
use CarlJanzell\FilamentFeedback\Support\Compat;
use CarlJanzell\FilamentFeedback\Widgets\FeedbackStats;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Throwable;

/**
 * Where triagers read every post, reply and set a status. Registered by the plugin on panels
 * with `->inbox()` and open only to people `->canTriage()` lets in.
 */
class FeedbackInbox extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'feedback';

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
        $new = FeedbackPost::query()->where('status', FeedbackStatus::New)->count();

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

        return $table
            ->query(FeedbackPost::query()->with('author')->withCount('replies'))
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
                TextColumn::make('replies_count')
                    ->label('Replies')
                    ->numeric()
                    ->alignCenter(),
                TextColumn::make('status')
                    ->badge(),
            ])
            ->filters([
                SelectFilter::make('status')->options(FeedbackStatus::class),
                SelectFilter::make('kind')->label('Type')->options(collect(FeedbackKind::cases())->mapWithKeys(fn (FeedbackKind $kind): array => [$kind->value => $kind->shortLabel()])->all()),
                SelectFilter::make('mood')->options(FeedbackMood::class),
                SelectFilter::make('module')
                    ->options($modules)
                    ->visible($modules !== [])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereJsonContains('modules', $data['value'])
                        : $query),
            ], layout: FiltersLayout::AboveContent)
            ->filtersFormColumns(4)
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
                    ]))
                    ->fillForm(function (FeedbackPost $record): array {
                        // Opening a new post is reading it.
                        if ($record->status === FeedbackStatus::New) {
                            $record->update(['status' => FeedbackStatus::Seen]);
                        }

                        return ['status' => $record->status->value, 'reply' => null];
                    })
                    ->form([
                        Textarea::make('reply')
                            ->label('Reply')
                            ->helperText('The author sees this on My feedback and gets a notification.')
                            ->rows(4)
                            ->maxLength(5000),
                        ToggleButtons::make('status')
                            ->options(FeedbackStatus::class)
                            ->inline()
                            ->required(),
                    ])
                    ->modalSubmitActionLabel('Save')
                    ->action(fn (array $data, FeedbackPost $record) => $this->respond($record, $data)),
            ])
            ->recordAction('open')
            ->defaultSort('created_at', 'desc')
            ->striped()
            ->poll('30s')
            ->emptyStateIcon('heroicon-o-chat-bubble-left-right')
            ->emptyStateHeading('No feedback yet')
            ->emptyStateDescription('Posts from the Feedback button land here.');
    }

    /**
     * @param  array{reply?: string|null, status: FeedbackStatus|string}  $data
     */
    public function respond(FeedbackPost $post, array $data): void
    {
        $body = trim((string) ($data['reply'] ?? ''));

        if ($body !== '') {
            $this->notifyAuthor($post, $post->addReply(Filament::auth()->user(), $body, fromTeam: true));
        }

        // Filament 4+ hands enum options back as enums, Filament 3 as their values.
        $post->update(['status' => $data['status'] instanceof FeedbackStatus ? $data['status'] : FeedbackStatus::from($data['status'])]);

        Notification::make()->title($body !== '' ? 'Reply sent' : 'Saved')->success()->send();
    }

    /**
     * A triager's reaction on a post or reply, from the thread in the Open slide-over.
     *
     * @param  'post'|'reply'  $type
     */
    public function react(string $type, int $id, string $reaction): void
    {
        abort_unless(FilamentFeedbackPlugin::get()->isTriager(), 403);

        $target = $type === 'post' ? FeedbackPost::query()->find($id) : FeedbackReply::query()->find($id);
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
     * @return array<class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [FeedbackStats::class];
    }

    protected function notifyAuthor(FeedbackPost $post, FeedbackReply $reply): void
    {
        $author = $post->author;

        if (! $author || ! method_exists($author, 'notify')) {
            return;
        }

        // The thread on My feedback, on the panel the post was written on.
        $url = FilamentFeedbackPlugin::threadUrl($post->panel, $post->getKey());

        // The reply is saved either way; a failed notification must not undo that for the triager.
        try {
            $plugin = FilamentFeedbackPlugin::get();

            if ($custom = $plugin->getNotifyAuthorUsing()) {
                $plugin->evaluate($custom, ['post' => $post, 'reply' => $reply, 'author' => $author, 'url' => $url]);

                return;
            }

            Notification::make()
                ->title('The team replied to your '.Str::lower($post->kind->shortLabel()))
                ->body(Str::limit(Str::squish($reply->body), 140))
                ->icon('heroicon-o-chat-bubble-left-right')
                ->actions($url ? [Compat::notificationAction('read')->label('Read the reply')->url($url)] : [])
                ->sendToDatabase($author);
        } catch (Throwable $exception) {
            report($exception);
        }
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
