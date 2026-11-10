@php
    use CarlJanzell\FilamentFeedback\Enums\FeedbackMood;
    use CarlJanzell\FilamentFeedback\Support\Compat;

    $moods = FeedbackMood::cases();
@endphp

<x-filament::section
    icon="heroicon-o-chart-bar-square"
    heading="How it's landing"
    description="Mood per module, from posts sent as feedback. A post that names two modules counts in both."
    collapsible
>
    @if ($board === [])
        <p class="ffb-muted">The board fills in as people send feedback with a mood.</p>
    @else
        <div class="ffb-board-scroll">
            <table class="ffb-board" style="--ffb-success: {{ Compat::color('success', 600) }}; --ffb-danger: {{ Compat::color('danger', 600) }}">
                <thead>
                    <tr>
                        <th scope="col">Module</th>
                        @foreach ($moods as $mood)
                            <th scope="col" class="ffb-num">{{ $mood->getLabel() }}</th>
                        @endforeach
                        <th scope="col" class="ffb-num">Posts</th>
                        <th scope="col" class="ffb-num" title="From −2 (angry) to +2 (love it)">Average</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($board as $row)
                        <tr>
                            <th scope="row">{{ $row['label'] }}</th>
                            @foreach ($moods as $mood)
                                @php
                                    $count = $row['counts'][$mood->value];
                                    $share = (int) round($count / $row['total'] * 55);
                                @endphp
                                <td
                                    class="ffb-num ffb-cell"
                                    style="--ffb-cell: {{ $count ? 'color-mix(in srgb, '.Compat::color($mood->getColor()).' '.$share.'%, transparent)' : 'transparent' }}"
                                >{{ $count ?: '·' }}</td>
                            @endforeach
                            <td class="ffb-num ffb-strong">{{ $row['total'] }}</td>
                            <td @class([
                                'ffb-num ffb-strong',
                                'ffb-up' => $row['average'] > 0,
                                'ffb-down' => $row['average'] < 0,
                            ])>{{ $row['average'] > 0 ? '+' : '' }}{{ number_format($row['average'], 1) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament::section>
