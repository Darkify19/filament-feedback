<?php

/**
 * A few weeks of feedback in a fictional business app, for screenshots. Loaded by
 * `server.php setup demo`; serve with FFB_DEMO=1 so the panel offers these modules.
 */

use CarlJanzell\FilamentFeedback\Enums\Reaction;
use CarlJanzell\FilamentFeedback\Models\FeedbackPost;
use CarlJanzell\FilamentFeedback\Models\FeedbackReply;
use CarlJanzell\FilamentFeedback\Tests\Fixtures\Models\User;
use Illuminate\Support\Carbon;

mt_srand(7);

$people = collect(['Carlos Reyes', 'Mira Santos', 'Jonah Lim', 'Priya Nair', 'Tom Becker', 'Lea Cruz', 'Sam Okafor', 'Ines Duarte'])
    ->map(fn (string $name): User => User::create(['name' => $name, 'email' => str($name)->slug().'@example.com', 'password' => 'x']));
$ana = tap(User::find(1))->update(['name' => 'Ana Ramos']);
User::find(2)->update(['name' => 'Ben Torres']);
$team = collect([User::find(2), User::create(['name' => 'Kai Moreno', 'email' => 'kai@example.com', 'password' => 'x', 'is_admin' => true])]);

// [kind, mood, modules, body, status, days ago, author (null = anyone), [[from team, body, hours later], …]]
$posts = [
    ['feedback', 2, ['orders'], 'The new order screen is so much faster. I can find a customer\'s last order in two clicks.', 'done', 40, null, [[true, 'Thank you! Speed was the main goal of the redesign.', 5]]],
    ['problem', -2, ['invoices'], 'An invoice PDF shows last month\'s exchange rate for EUR orders.', 'done', 37, null, [[true, 'Found it: the rate was cached per month instead of per day. Fixed, and the affected invoices are regenerated.', 3], [false, 'Checked a few, they\'re right now. Thanks!', 20]]],
    ['question', null, ['reports'], 'How do I export the sales report for one region only?', 'done', 35, null, [[true, 'Filter by region first, then Export: it exports what the table shows.', 2]]],
    ['feedback', -1, ['orders'], 'The order list is hard to read on my phone, the totals overlap the status.', 'planned', 33, null, [[true, 'We see it too. A phone layout for the order list is planned for the next release.', 26]]],
    ['problem', -1, ['customers'], 'Importing customers fails when the CSV has more than 5,000 rows.', 'done', 30, null, [[true, 'We raised the limit to 50,000 rows and import in the background now.', 30], [false, 'Imported 12k rows without a problem.', 6]]],
    ['feedback', 1, ['design'], 'Dark mode looks great. The accent is a bit bright at night though.', 'seen', 28, null, []],
    ['question', null, ['invoices'], 'Can a customer have two billing addresses?', 'done', 26, null, [[true, 'Yes: add a second address on the customer and pick it on the invoice.', 4]]],
    ['feedback', 2, ['reports'], 'The weekly #Reports email is exactly what our managers needed.', 'seen', 25, null, [[true, 'Glad to hear it!', 50]]],
    ['problem', -2, ['invoices'], 'The company logo prints twice on invoices with more than one page.', 'done', 23, null, [[true, 'Fixed: the header now repeats without the logo on later pages.', 8]]],
    ['feedback', 0, ['reports'], 'Not sure the new charts are better. I liked seeing the numbers in a table.', 'new', 21, null, []],
    ['developers', null, ['orders'], 'The order export runs one query per line item. For big orders that\'s hundreds of queries; worth eager loading.', 'planned', 20, null, [[true, 'Good catch. Planned with the export rewrite.', 18]]],
    ['problem', -1, ['leave'], 'My leave balance didn\'t update after the request was approved.', 'done', 19, null, [[true, 'Balances update overnight. It should be right now, can you check?', 10], [false, 'Yes, correct now.', 3]]],
    ['feedback', 1, ['customers'], 'Love the new customer timeline. Would be nice to see open invoices there too.', 'planned', 17, null, [[true, 'Open invoices on the timeline is on the list.', 22]]],
    ['question', null, ['orders'], 'Can I assign an order to two people at once?', 'seen', 16, null, []],
    ['feedback', -2, ['orders', 'invoices'], 'Splitting an order into two invoices is confusing: it warns about a conflict and won\'t save.', 'planned', 14, null, [[true, 'That check is too strict. Planned.', 6], [false, 'Thanks. For now I invoice them separately.', 30]]],
    ['problem', -1, ['reports'], 'Sales after midnight count for the next day in the daily report.', 'seen', 13, null, [[true, 'Confirmed, it uses UTC. Looking into it.', 28]]],
    ['feedback', 2, ['leave'], 'Requesting leave took me under a minute. Thank you!', 'done', 12, null, [[true, 'Thanks for telling us!', 3]]],
    ['developers', null, [], 'Idea: a webhook on new orders so the warehouse system doesn\'t have to poll.', 'new', 10, null, []],
    ['feedback', -1, ['invoices'], 'Sending 30 invoices one by one is tiring. A send-all would help a lot.', 'planned', 9, null, [[true, 'Bulk sending is planned.', 12]]],
    ['question', null, ['customers'], 'Where do I set a customer\'s payment terms?', 'done', 8, null, [[true, 'Customer → Billing tab → Payment terms.', 1]]],
    ['problem', -2, ['invoices'], 'The invoice PDF cuts off the last line item on A4.', 'new', 7, null, []],
    ['feedback', 1, ['customers'], 'The new customer search finds people by phone number now. Small thing, big help.', 'seen', 6, null, []],
    ['feedback', 0, ['design'], 'The sidebar has a lot of items. Maybe group them?', 'new', 5, null, []],
    ['problem', -1, ['orders'], 'Due dates show in UTC: my 5 PM deadline shows as 9 AM.', 'seen', 4, null, [[true, 'Thanks, reproduced. A fix is coming this week.', 4]]],
    ['feedback', 2, ['reports'], 'The new #Reports dashboard is exactly what our team needed.', 'new', 3, null, []],
    ['question', null, ['leave'], 'Do I need to attach a medical certificate for one day of sick leave?', 'new', 2, null, []],
    ['feedback', -1, ['orders'], 'Order notes are cut after 200 characters.', 'seen', 1, null, [[true, 'We\'ll raise the limit.', 5]]],
    ['problem', -2, ['leave'], 'I can\'t cancel a leave request I sent by mistake.', 'new', 0, null, []],
    // Ana's own threads, for My feedback.
    ['feedback', -1, ['orders', 'reports'], 'The order count on the dashboard and in the sales report disagree for October 28.', 'done', 11, $ana, [[true, 'The dashboard skipped cancelled-then-restored orders. Fixed, both say 214 now.', 6], [false, 'Confirmed, thanks!', 2]]],
    ['question', null, ['leave'], 'Is there a way to see the leave balances of my whole team?', 'planned', 5, $ana, [[true, 'Not yet. A team leave summary is planned.', 3], [true, 'Update: it lands in the next release, under Leave → Team balances.', 40]]],
    ['problem', -1, ['invoices'], 'Changing the currency on a draft invoice resets the discount.', 'seen', 1, $ana, [[true, 'Seen, reproducing it now.', 2]]],
];

$flow = ['new' => [], 'seen' => ['seen'], 'planned' => ['seen', 'planned'], 'done' => ['seen', 'planned', 'done']];

foreach ($posts as [$kind, $mood, $modules, $body, $status, $daysAgo, $author, $thread]) {
    $author ??= $people->random();
    $at = Carbon::now()->subDays($daysAgo)->setTime(mt_rand(8, 16), mt_rand(0, 59))->min(now()->subHour());
    $replyAt = $at->copy();
    $replies = [];

    foreach ($thread as [$fromTeam, $text, $hours]) {
        $replyAt = $replyAt->copy()->addHours($hours)->min(now());
        $replies[] = [$fromTeam, $text, $replyAt];
    }

    $stepAt = $replies[0][2] ?? $at->copy()->addHours(6)->min(now());
    $log = array_map(fn (string $step, int $i): array => ['status' => $step, 'at' => $stepAt->copy()->addHours($i * 8)->min(now())->toIso8601String()], $flow[$status], array_keys($flow[$status]));

    $post = new FeedbackPost([
        'panel' => 'testing',
        'kind' => $kind,
        'mood' => $kind === 'feedback' ? $mood : null,
        'modules' => $modules ?: null,
        'body' => $body,
        'status' => $status,
        'status_log' => $log ?: null,
        'fix_confirmed' => $status === 'done' ? [true, true, null][mt_rand(0, 2)] : null,
        'context' => ['url' => url('/testing/'.($modules[0] ?? '')), 'route' => 'filament.testing.pages.dashboard'],
        // Ana's newest team replies stay unread, so the badge shows.
        'author_seen_at' => $author->is($ana) && $daysAgo <= 5 ? $at : $replyAt,
    ]);
    $post->author()->associate($author);
    $post->created_at = $post->updated_at = $at;
    $post->save();

    foreach ($replies as $i => [$fromTeam, $text, $when]) {
        $reply = new FeedbackReply(['body' => $text, 'is_from_team' => $fromTeam]);
        $reply->post()->associate($post);
        $reply->author()->associate($fromTeam ? $team[$i % 2] : $author);
        $reply->created_at = $reply->updated_at = $when;
        $reply->save();

        if ($fromTeam && mt_rand(0, 2) === 0) {
            $reply->toggleReaction($author, Reaction::Thanks);
        }
    }

    foreach ($people->reject(fn (User $user): bool => $user->is($author))->random(mt_rand(0, 4)) as $other) {
        $post->toggleReaction($other, [Reaction::Same, Reaction::Same, Reaction::Thanks][mt_rand(0, 2)]);
    }

    if ($status !== 'new') {
        $post->toggleReaction($team[0], $status === 'done' ? Reaction::Fixed : Reaction::Seen);
    }
}

echo count($posts)." demo posts seeded.\n";
