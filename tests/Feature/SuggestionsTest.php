<?php

use CarlJanzell\FilamentFeedback\Actions\ImportSuggestions;
use CarlJanzell\FilamentFeedback\Enums\FeedbackStatus;
use CarlJanzell\FilamentFeedback\Enums\SuggestionOutcome;
use CarlJanzell\FilamentFeedback\FilamentFeedbackPlugin;
use CarlJanzell\FilamentFeedback\Models\FeedbackPost;
use CarlJanzell\FilamentFeedback\Models\FeedbackSuggestion;
use CarlJanzell\FilamentFeedback\Pages\FeedbackInbox;
use CarlJanzell\FilamentFeedback\Widgets\FeedbackStats;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;

/**
 * @param  array<string, mixed>  $attributes
 */
function suggest(FeedbackPost $post, array $attributes = []): FeedbackSuggestion
{
    $report = app(ImportSuggestions::class)->execute(['version' => 1, 'drafted_by' => 'Claude · feedback-reply', 'suggestions' => [
        ['post_id' => $post->id, 'reply' => 'Hi po! Use Add missed punch on My Attendance.', 'status' => 'done', 'confidence' => 'high', ...$attributes],
    ]]);

    expect($report['skipped'])->toBe([]);

    return $post->pendingSuggestion()->sole();
}

it('imports suggestions, skips what it cannot use, and supersedes the older draft', function (): void {
    $post = post(person());
    $other = post(person('Ben'));
    $import = app(ImportSuggestions::class);
    $payload = ['version' => 1, 'drafted_by' => 'Claude · feedback-reply', 'suggestions' => [
        ['post_id' => $post->id, 'reply' => '  First draft ', 'status' => 'done', 'confidence' => 'high', 'sources' => ['dtr-guide#s-fix', 3]],
        ['post_id' => 999, 'reply' => 'Hello'],
        ['post_id' => $other->id, 'reply' => 'Hello', 'status' => 'closed'],
        ['post_id' => $other->id, 'reply' => '', 'status' => null],
        ['post_id' => $other->id, 'reply' => 'Hello', 'confidence' => 'certain'],
    ]];

    $skipped = [
        ['post_id' => 999, 'reason' => 'unknown post'],
        ['post_id' => $other->id, 'reason' => 'invalid status "closed"'],
        ['post_id' => $other->id, 'reason' => 'no reply and no status'],
        ['post_id' => $other->id, 'reason' => 'invalid confidence "certain"'],
    ];

    expect($import->execute($payload, dryRun: true))->toBe(['created' => 1, 'superseded' => 0, 'skipped' => $skipped])
        ->and(FeedbackSuggestion::count())->toBe(0);

    $import->execute($payload);
    $first = $post->pendingSuggestion()->sole();

    expect($first->body)->toBe('First draft')
        ->and($first->status)->toBe(FeedbackStatus::Done)
        ->and($first->sources)->toBe(['dtr-guide#s-fix'])
        ->and($first->drafted_by)->toBe('Claude · feedback-reply');

    $report = $import->execute(['version' => 1, 'suggestions' => [['post_id' => $post->id, 'reply' => 'Second draft']]]);

    expect($report)->toBe(['created' => 1, 'superseded' => 1, 'skipped' => []])
        ->and($first->fresh()->outcome)->toBe(SuggestionOutcome::Superseded)
        ->and($post->pendingSuggestion()->sole()->body)->toBe('Second draft')
        ->and(fn () => $import->execute(['version' => 2, 'suggestions' => []]))->toThrow(InvalidArgumentException::class);
});

it('goes stale when someone writes in the thread after the draft', function (): void {
    $ana = person();
    $post = post($ana);
    $post->addReply($ana, 'Hello?', fromTeam: false);

    // Left out, for_reply_id means "drafted against the thread as it is now".
    $suggestion = suggest($post);
    expect($suggestion->for_reply_id)->toBe($post->replies()->sole()->id)
        ->and($suggestion->isStale())->toBeFalse();

    $post->addReply($ana, 'It happened again today.', fromTeam: false);

    expect($suggestion->fresh()->isStale())->toBeTrue()
        ->and(suggest($post, ['for_reply_id' => null])->isStale())->toBeTrue();
});

it('sends the safe suggestions as the triager and skips low-confidence and stale ones', function (): void {
    $ana = person();
    $hr = signIn(person('Hr', admin: true));
    FilamentFeedbackPlugin::get()->suggestions();

    $safe = post($ana);
    $low = post($ana);
    $stale = post($ana);
    $quiet = post($ana, ['body' => 'testing']);
    suggest($safe);
    suggest($low, ['confidence' => 'low']);
    suggest($stale, ['for_reply_id' => null]);
    suggest($quiet, ['reply' => null, 'status' => 'done']);
    $stale->addReply($ana, 'Never mind, found it.', fromTeam: false);
    $this->travel(1)->minute();

    Livewire::test(FeedbackInbox::class)
        ->assertTableActionVisible('sendSuggested')
        ->callTableAction('sendSuggested')
        ->assertNotified('Sent 2 · skipped 2 (1 low confidence, 1 thread changed)')
        ->assertDispatched(FeedbackStats::REFRESH);

    $sent = $safe->suggestions()->sole();

    expect($safe->fresh()->status)->toBe(FeedbackStatus::Done)
        ->and($safe->replies()->sole()->author->is($hr))->toBeTrue()
        ->and($sent->outcome)->toBe(SuggestionOutcome::SentAsIs)
        ->and($sent->final_reply_id)->toBe($safe->replies()->sole()->id)
        ->and($sent->decidedBy->is($hr))->toBeTrue()
        // A status-only suggestion closes the post without a reply.
        ->and($quiet->fresh()->status)->toBe(FeedbackStatus::Done)
        ->and($quiet->replies()->count())->toBe(0)
        ->and($ana->notifications()->count())->toBe(1)
        ->and($low->pendingSuggestion)->not->toBeNull()
        ->and($stale->pendingSuggestion)->not->toBeNull();

    // Picked by hand, a low-confidence draft goes too; a stale one never does.
    Livewire::test(FeedbackInbox::class)
        ->callTableBulkAction('sendSuggestions', [$low, $stale])
        ->assertNotified('Sent 1 · skipped 1 (1 thread changed)');

    expect($low->replies()->count())->toBe(1)
        ->and($stale->fresh()->pendingSuggestion)->not->toBeNull();
});

it('fills the reply box from the suggestion and records whether it went out as is or edited', function (): void {
    $ana = person();
    signIn(person('Hr', admin: true));
    FilamentFeedbackPlugin::get()->suggestions();

    $asIs = post($ana);
    $edited = post($ana);
    $first = suggest($asIs, ['status' => 'planned']);
    $second = suggest($edited);

    Livewire::test(FeedbackInbox::class)
        ->mountTableAction('open', $asIs)
        ->assertTableActionDataSet(['reply' => $first->body, 'suggestion' => $first->id]);

    // Saved without touching the status: the suggestion's "planned" was pre-selected.
    Livewire::test(FeedbackInbox::class)
        ->callTableAction('open', $asIs, data: ['reply' => "  {$first->body}\n"])
        ->assertHasNoTableActionErrors();

    Livewire::test(FeedbackInbox::class)
        ->callTableAction('open', $edited, data: ['reply' => 'Hi po! Please file it on My Attendance.'])
        ->assertHasNoTableActionErrors();

    expect($first->fresh()->outcome)->toBe(SuggestionOutcome::SentAsIs)
        ->and($asIs->fresh()->status)->toBe(FeedbackStatus::Planned)
        ->and($second->fresh()->outcome)->toBe(SuggestionOutcome::SentEdited)
        ->and($second->fresh()->finalReply->body)->toBe('Hi po! Please file it on My Attendance.');

    // Discarded: the box opens empty, and nothing is waiting.
    $third = suggest($edited);
    expect($third->discard(person('Cy', admin: true)))->toBeTrue()
        ->and($third->discard(person('Di', admin: true)))->toBeFalse()
        ->and($third->fresh()->outcome)->toBe(SuggestionOutcome::Discarded);

    Livewire::test(FeedbackInbox::class)
        ->mountTableAction('open', $edited)
        ->assertTableActionDataSet(['reply' => null, 'suggestion' => null]);
});

it('shows no suggestion UI while suggestions are off', function (): void {
    $post = post(person());
    signIn(person('Hr', admin: true));
    suggest($post);

    Livewire::test(FeedbackInbox::class)
        ->assertTableActionDoesNotExist('sendSuggested')
        ->mountTableAction('open', $post)
        ->assertTableActionDataSet(['reply' => null, 'suggestion' => null]);

    // On, but not for someone who isn't a triager.
    FilamentFeedbackPlugin::get()->suggestions();
    signIn(person('Ana'));
    expect(FilamentFeedbackPlugin::get()->hasSuggestions())->toBeFalse();
});

it('exports the posts waiting on the team, and how earlier drafts ended', function (): void {
    $ana = person();
    $hr = person('Hr', admin: true);
    $waiting = post($ana);
    $answered = post($ana, ['status' => 'planned']);
    $answered->addReply($hr, 'On the list.', fromTeam: true);
    $authorLast = post($ana, ['status' => 'seen']);
    $authorLast->addReply($hr, 'Which dates?', fromTeam: true);
    $authorLast->addReply($ana, 'Nov 13.', fromTeam: false);
    post($ana, ['status' => 'done']);
    $drafted = post($ana);
    suggest($drafted);
    suggest($waiting)->discard($hr);

    Artisan::call('feedback:export', ['--open' => true]);
    $export = json_decode(Artisan::output(), true);

    expect(array_column($export['posts'], 'id'))->toBe([$waiting->id, $authorLast->id])
        ->and($export['posts'][1]['replies'][1])->toMatchArray(['is_from_team' => false, 'body' => 'Nov 13.', 'unsent' => false])
        ->and($export['posts'][0]['author'])->toBe(['type' => $ana->getMorphClass(), 'id' => $ana->id])
        ->and($export['recent_outcomes'])->toBe([['post_id' => $waiting->id, 'draft' => 'Hi po! Use Add missed punch on My Attendance.', 'final' => null, 'outcome' => 'discarded']]);

    Artisan::call('feedback:export', ['--post' => [$drafted->id]]);
    expect(json_decode(Artisan::output(), true)['posts'][0]['pending_suggestion_id'])->toBe($drafted->pendingSuggestion->id);
});
