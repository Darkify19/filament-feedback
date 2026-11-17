<?php

/**
 * Posts with suggested replies in every state the inbox shows: safe to send, low confidence,
 * stale, status-only, none. Loaded by `server.php setup suggestions`.
 */

use CarlJanzell\FilamentFeedback\Actions\ImportSuggestions;
use CarlJanzell\FilamentFeedback\Models\FeedbackPost;
use CarlJanzell\FilamentFeedback\Tests\Fixtures\Models\User;

$ana = User::find(1);
$write = function (array $attributes) use ($ana): FeedbackPost {
    $post = new FeedbackPost(['panel' => 'testing', 'kind' => 'question', 'modules' => ['dtr'], 'author_seen_at' => now(), ...$attributes]);
    $post->author()->associate($ana)->save();

    return $post;
};

$missed = $write(['body' => 'I missed a clock lunch out. What to do? Thanks!']);
$story = $write(['kind' => 'feedback', 'mood' => -1, 'body' => 'Good day! I clocked out for lunch on Friday but forgot to clock back in, and only noticed when I clocked out at the end of the day. What should I do?']);
$hours = $write(['modules' => ['leave'], 'body' => 'How can I change my working hours to 7am to 4pm?']);
$test = $write(['kind' => 'developers', 'modules' => [], 'body' => 'testing']);
$stale = $write(['kind' => 'problem', 'body' => 'There\'s an absent and a late in my DTR. What should I do?', 'status' => 'seen']);
$laptop = $write(['kind' => 'problem', 'body' => 'On my laptop I can\'t see the sign-out button.']);
$write(['kind' => 'feedback', 'mood' => 2, 'body' => 'The new DTR page is much easier to read.']);

app(ImportSuggestions::class)->execute(['version' => 1, 'drafted_by' => 'Claude · feedback-reply', 'suggestions' => [
    ['post_id' => $missed->id, 'for_reply_id' => null, 'reply' => "Hi! You can add it yourself:\n1. Open My Attendance.\n2. Press Add missed punch.\n3. Pick the date, choose Lunch in, enter the time, and add a short reason.", 'status' => 'done', 'confidence' => 'high', 'sources' => ['attendance-guide#s-manual']],
    ['post_id' => $story->id, 'for_reply_id' => null, 'reply' => "Hi! Checked your Friday punches: the lunch-in is missing. Add it on My Attendance with Add missed punch. Your office's window for this is open until Friday.", 'status' => 'done', 'confidence' => 'high', 'note' => 'Checked the author\'s punches for that day: lunch-in missing. Manual entry window for their office is open until Nov 20.', 'sources' => ['dtr-guide#s-fix', 'author\'s Nov 13 punches']],
    ['post_id' => $hours->id, 'for_reply_id' => null, 'reply' => 'Hi! Ask from your profile: Profile → Work schedule → Change my usual shift. Your timekeeper reviews it.', 'status' => 'planned', 'confidence' => 'medium', 'sources' => ['schedule-guide#s-req']],
    ['post_id' => $test->id, 'for_reply_id' => null, 'reply' => null, 'status' => 'done', 'confidence' => 'high', 'note' => 'test/noise'],
    ['post_id' => $stale->id, 'for_reply_id' => null, 'reply' => 'Hi! Which dates show the absent and the late?', 'status' => 'seen', 'confidence' => 'medium'],
    ['post_id' => $laptop->id, 'for_reply_id' => null, 'reply' => 'Hi! Thanks for telling us. Try zooming out (Ctrl and minus) for now; we\'ve passed it to the developers.', 'status' => 'planned', 'confidence' => 'low', 'note' => 'Policy unclear: may need HR to decide. Repro guess: header actions wrap off-screen below 1280px.'],
]]);

// The author wrote again after the draft: it's stale now.
$stale->addReply($ana, 'It was on November 3 and 4.', fromTeam: false);

echo "Seeded 7 posts with suggestions in every state.\n";
