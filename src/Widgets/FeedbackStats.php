<?php

namespace CarlJanzell\FilamentFeedback\Widgets;

use CarlJanzell\FilamentFeedback\Enums\FeedbackKind;
use CarlJanzell\FilamentFeedback\Enums\FeedbackMood;
use CarlJanzell\FilamentFeedback\Enums\FeedbackStatus;
use CarlJanzell\FilamentFeedback\Models\FeedbackPost;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/**
 * How feedback is going over the last 30 days: volume, how fast the team answers, what got
 * done, and the mood against the 30 days before. Sits on top of the inbox; a plain Filament
 * widget, so filament-insights can place it too (`FilamentInsightsPlugin::make()->widgets([...])`).
 */
class FeedbackStats extends StatsOverviewWidget
{
    public const DAYS = 30;

    public static function canView(): bool
    {
        // Elsewhere (an insights dashboard on another panel) the host decides who sees it.
        $panel = Filament::getCurrentPanel();

        return ! $panel?->hasPlugin('feedback') || $panel->getPlugin('feedback')->isTriager();
    }

    protected function getPollingInterval(): ?string
    {
        return null;
    }

    protected function getColumns(): int
    {
        return 4;
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $since = now()->subDays(static::DAYS)->startOfDay();

        return [
            $this->volume($since),
            $this->firstReply($since),
            $this->done($since),
            $this->mood($since),
        ];
    }

    protected function volume(Carbon $since): Stat
    {
        $perDay = FeedbackPost::query()->visibleToTeam()->where('created_at', '>=', now()->subDays(13)->startOfDay())->pluck('created_at')
            ->countBy(fn (Carbon $at): string => $at->toDateString());
        $open = FeedbackPost::query()->visibleToTeam()->whereIn('status', [FeedbackStatus::New, FeedbackStatus::Seen, FeedbackStatus::Planned])->count();
        $new = FeedbackPost::query()->visibleToTeam()->where('status', FeedbackStatus::New)->count();

        return Stat::make('Posts, last '.static::DAYS.' days', FeedbackPost::query()->visibleToTeam()->where('created_at', '>=', $since)->count())
            ->description("{$open} open · {$new} waiting for a first look")
            ->descriptionIcon('heroicon-m-inbox')
            ->chart(collect(range(13, 0))->map(fn (int $daysAgo): int => $perDay[now()->subDays($daysAgo)->toDateString()] ?? 0)->all())
            ->color($new > 0 ? 'warning' : 'gray');
    }

    /**
     * Median time from a post to the team's first reply, over posts that got one.
     */
    protected function firstReply(Carbon $since): Stat
    {
        // ponytail: median in PHP over one month of posts; move to SQL percentile if a month holds tens of thousands.
        $hours = FeedbackPost::query()
            ->where('created_at', '>=', $since)
            ->withMin(['replies as first_team_reply_at' => fn ($replies) => $replies->where('is_from_team', true)], 'created_at')
            ->get(['id', 'created_at'])
            ->filter(fn (FeedbackPost $post): bool => $post->first_team_reply_at !== null)
            ->map(fn (FeedbackPost $post): float => $post->created_at->diffInMinutes(Carbon::parse($post->first_team_reply_at), true) / 60)
            ->sort()
            ->values();

        $total = FeedbackPost::query()->visibleToTeam()->where('created_at', '>=', $since)->count();

        return Stat::make('First reply, median', $hours->isEmpty() ? '—' : static::duration($hours->median()))
            ->description($hours->count().' of '.$total.' answered')
            ->descriptionIcon('heroicon-m-clock')
            ->color('gray');
    }

    protected function done(Carbon $since): Stat
    {
        $done = FeedbackPost::query()->visibleToTeam()->where('status', FeedbackStatus::Done)->where('updated_at', '>=', $since);
        $confirmed = (clone $done)->where('fix_confirmed', true)->count();
        $notFixed = FeedbackPost::query()->visibleToTeam()->where('fix_confirmed', false)->where('updated_at', '>=', $since)->count();

        return Stat::make('Done, last '.static::DAYS.' days', $done->count())
            ->description("{$confirmed} confirmed fixed · {$notFixed} not fixed")
            ->descriptionIcon('heroicon-m-check-circle')
            ->color(match (true) {
                $notFixed > 0 => 'danger',
                $confirmed > 0 => 'success',
                default => 'gray',
            });
    }

    protected function mood(Carbon $since): Stat
    {
        $average = fn (Carbon $from, Carbon $to): ?float => FeedbackPost::query()
            ->where('kind', FeedbackKind::Feedback)
            ->whereNotNull('mood')
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->avg('mood');

        $now = $average($since, now()->addSecond());
        $before = $average($since->copy()->subDays(static::DAYS), $since);

        if ($now === null) {
            return Stat::make('Mood', '—')->description('No feedback with a mood yet')->color('gray');
        }

        $label = FeedbackMood::from((int) round($now))->getLabel();
        $change = $before === null ? null : round($now - $before, 1);

        return Stat::make('Mood', sprintf('%+.1f', $now))
            ->description($change === null
                ? "Closest to {$label}"
                : "Closest to {$label} · ".sprintf('%+.1f', $change).' vs the 30 days before')
            ->descriptionIcon(match (true) {
                $change === null || $change == 0.0 => 'heroicon-m-minus',
                $change > 0 => 'heroicon-m-arrow-trending-up',
                default => 'heroicon-m-arrow-trending-down',
            })
            ->color(match (true) {
                $change === null || $change == 0.0 => 'gray',
                $change > 0 => 'success',
                default => 'danger',
            });
    }

    public static function duration(float $hours): string
    {
        return match (true) {
            $hours < 1 => max(1, (int) round($hours * 60)).'m',
            $hours < 48 => round($hours, $hours < 10 ? 1 : 0).'h',
            default => round($hours / 24, 1).'d',
        };
    }
}
