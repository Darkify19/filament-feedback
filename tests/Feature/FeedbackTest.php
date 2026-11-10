<?php

use CarlJanzell\FilamentFeedback\Enums\FeedbackMood;
use CarlJanzell\FilamentFeedback\Enums\FeedbackStatus;
use CarlJanzell\FilamentFeedback\Enums\Reaction;
use CarlJanzell\FilamentFeedback\FilamentFeedbackPlugin;
use CarlJanzell\FilamentFeedback\Livewire\FeedbackLauncher;
use CarlJanzell\FilamentFeedback\Models\FeedbackPost;
use CarlJanzell\FilamentFeedback\Pages\FeedbackInbox;
use CarlJanzell\FilamentFeedback\Pages\MyFeedback;
use CarlJanzell\FilamentFeedback\Support\GoogleChatPing;
use CarlJanzell\FilamentFeedback\Widgets\FeedbackStats;
use Livewire\Livewire;

it('saves a post with its modules, mood and the page it came from', function (): void {
    $ana = signIn(person());

    Livewire::test(FeedbackLauncher::class, ['panel' => 'testing'])
        ->callAction('feedback', data: [
            'kind' => 'feedback',
            'modules' => ['dtr'],
            'mood' => '-2',
            'body' => '  The new DTR layout hides my undertime.  ',
        ])
        ->assertHasNoActionErrors();

    $post = FeedbackPost::sole();

    expect($post->author->is($ana))->toBeTrue()
        ->and($post->modules)->toBe(['dtr'])
        ->and($post->mood)->toBe(FeedbackMood::Angry)
        ->and($post->status)->toBe(FeedbackStatus::New)
        ->and($post->body)->toBe('The new DTR layout hides my undertime.')
        ->and($post->context)->toHaveKey('url');
});

it('never stores a module the app does not offer', function (): void {
    signIn(person());

    Livewire::test(FeedbackLauncher::class, ['panel' => 'testing'])
        ->callAction('feedback', data: ['kind' => 'feedback', 'modules' => ['dtr', 'payroll'], 'mood' => '1', 'body' => 'Hi']);

    // Filament 4+ rejects the post outright; Filament 3 doesn't validate each item, so the launcher drops it.
    expect(FeedbackPost::first()?->modules ?? ['dtr'])->toBe(['dtr']);
});

it('asks for a module and a mood on feedback, but not on a question', function (): void {
    signIn(person());

    Livewire::test(FeedbackLauncher::class, ['panel' => 'testing'])
        ->callAction('feedback', data: ['kind' => 'feedback', 'modules' => [], 'mood' => null, 'body' => 'Hmm'])
        ->assertHasActionErrors(['modules' => 'required', 'mood' => 'required']);

    Livewire::test(FeedbackLauncher::class, ['panel' => 'testing'])
        ->callAction('feedback', data: ['kind' => 'question', 'modules' => [], 'mood' => null, 'body' => 'Where is my leave balance?'])
        ->assertHasNoActionErrors();

    expect(FeedbackPost::sole()->mood)->toBeNull();
});

it('tags modules mentioned with # in the message, and counts them as picked', function (): void {
    signIn(person());

    Livewire::test(FeedbackLauncher::class, ['panel' => 'testing'])
        ->callAction('feedback', data: ['kind' => 'feedback', 'modules' => [], 'mood' => '0', 'body' => 'Filing #leave from the #Dtr page. Not #payroll, and &#039; is no tag.'])
        ->assertHasNoActionErrors();

    expect(FeedbackPost::sole()->modules)->toBe(['leave', 'dtr'])
        ->and(FilamentFeedbackPlugin::mentionText('Break Room'))->toBe('BreakRoom');
});

it('lets the author reply only on their own posts, and reopens the post', function (): void {
    $ana = person();
    $ben = person('Ben');
    $anasPost = post($ana, ['status' => 'done']);
    $bensPost = post($ben);

    signIn($ana);

    Livewire::withQueryParams(['post' => $anasPost->id])->test(MyFeedback::class)
        ->set('draft', 'Still broken for me.')
        ->call('reply')
        ->assertHasNoErrors();

    expect($anasPost->replies()->sole()->is_from_team)->toBeFalse()
        ->and($anasPost->fresh()->status)->toBe(FeedbackStatus::New);

    // Someone else's post id falls back to the viewer's own newest thread.
    Livewire::withQueryParams(['post' => $bensPost->id])->test(MyFeedback::class)
        ->assertSet('post', $anasPost->id);

    expect($bensPost->replies()->count())->toBe(0);
});

it('keeps the inbox to triagers', function (): void {
    signIn(person());
    expect(FeedbackInbox::canAccess())->toBeFalse();

    signIn(person('Hr', admin: true));
    expect(FeedbackInbox::canAccess())->toBeTrue();
});

it('sends a team reply to the author, who sees it as unread until they open the thread', function (): void {
    $ana = person();
    $post = post($ana);

    signIn(person('Hr', admin: true));
    $this->travel(1)->minute();

    Livewire::test(FeedbackInbox::class)
        ->callTableAction('open', $post, data: ['reply' => 'Fixed in the next release.', 'status' => 'planned'])
        ->assertHasNoTableActionErrors();

    expect($post->fresh()->status)->toBe(FeedbackStatus::Planned)
        ->and($post->replies()->sole()->is_from_team)->toBeTrue()
        ->and($ana->notifications()->count())->toBe(1)
        ->and(json_encode($ana->notifications()->sole()->data))->toContain('my-feedback?post='.$post->id);

    signIn($ana);

    $launcher = Livewire::test(FeedbackLauncher::class, ['panel' => 'testing']);
    expect($launcher->instance()->unreadCount())->toBe(1);

    Livewire::withQueryParams(['post' => $post->id])->test(MyFeedback::class)
        ->assertSee('Fixed in the next release.');

    expect($launcher->instance()->unreadCount())->toBe(0);
});

it('logs every status change for the timeline', function (): void {
    $post = post(person());

    $post->update(['status' => 'seen']);
    $post->update(['status' => 'done']);
    $post->update(['status' => 'new']);

    expect(array_column($post->fresh()->timeline(), 'label'))->toBe(['Sent', 'Seen', 'Done', 'Reopened']);
});

it('asks the author whether a done post is fixed, and reopens it on a no', function (): void {
    $ana = person();
    $post = post($ana, ['status' => 'done']);

    signIn($ana);

    Livewire::withQueryParams(['post' => $post->id])->test(MyFeedback::class)
        ->assertSee('Did it fix it for you?')
        ->call('confirmFix', false);

    $post->refresh();
    expect($post->status)->toBe(FeedbackStatus::New)
        ->and($post->fix_confirmed)->toBeFalse()
        ->and($post->replies()->sole()->body)->toContain('still isn');

    // Done again: ask again.
    $post->update(['status' => 'done']);
    expect($post->fresh()->fix_confirmed)->toBeNull();
});

it('toggles reactions on the other side\'s messages only', function (): void {
    $ana = person();
    $hr = person('Hr', admin: true);
    $post = post($ana);
    $reply = $post->addReply($hr, 'On it.', fromTeam: true);

    signIn($ana);

    $page = Livewire::withQueryParams(['post' => $post->id])->test(MyFeedback::class)
        ->call('react', 'reply', $reply->id, 'thanks');
    expect($reply->reactions()->sole()->reaction)->toBe(Reaction::Thanks);

    $page->call('react', 'reply', $reply->id, 'thanks');
    expect($reply->reactions()->count())->toBe(0);

    $page->call('react', 'post', $post->id, 'seen')->assertNotFound();

    signIn($hr);
    Livewire::test(FeedbackInbox::class)->call('react', 'post', $post->id, 'seen');

    expect($post->fresh()->load('reactions')->reactionSummary($ana)[2])->toMatchArray(['count' => 1, 'mine' => false]);
});

it('sends a note to the developers without a module or mood, and keeps it from other triagers when asked', function (): void {
    $ana = signIn(person());

    Livewire::test(FeedbackLauncher::class, ['panel' => 'testing'])
        ->callAction('feedback', data: ['kind' => 'developers', 'modules' => [], 'mood' => null, 'body' => 'The export could stream instead of buffering.'])
        ->assertHasNoActionErrors();

    $note = FeedbackPost::sole();
    expect($note->kind->value)->toBe('developers')->and($note->mood)->toBeNull();

    $plugin = FilamentFeedbackPlugin::get()->chatWebhook('https://chat.example/hr')
        ->developerPosts(canRead: fn (): bool => auth()->user()?->name === 'Dev', chatWebhook: 'https://chat.example/dev');

    expect($plugin->getChatWebhook($note->kind))->toBe('https://chat.example/dev')
        ->and($plugin->getChatWebhook())->toBe('https://chat.example/hr');

    signIn(person('Hr', admin: true));
    Livewire::test(FeedbackInbox::class)->assertCanNotSeeTableRecords([$note]);

    signIn(person('Dev', admin: true));
    Livewire::test(FeedbackInbox::class)->assertCanSeeTableRecords([$note]);

    $plugin->developerPosts(false);
    expect(array_map(fn ($kind) => $kind->value, $plugin->getKinds()))->toBe(['feedback', 'question', 'problem']);
});

it('lets the team unsend its own replies, and authors only when allowed', function (): void {
    $ana = person();
    $hr = person('Hr', admin: true);
    $post = post($ana);
    $teamReply = $post->addReply($hr, 'Wrong thread, sorry.', fromTeam: true);
    $anasReply = $post->addReply($ana, 'Oops, typo.', fromTeam: false);

    signIn($hr);
    Livewire::test(FeedbackInbox::class)->call('unsend', $anasReply->id)->assertForbidden();
    Livewire::test(FeedbackInbox::class)->call('unsend', $teamReply->id);

    expect($teamReply->fresh()->isUnsent())->toBeTrue()
        ->and($teamReply->fresh()->body)->toBe('');

    signIn($ana);
    Livewire::withQueryParams(['post' => $post->id])->test(MyFeedback::class)
        ->assertSee('Hr unsent a message')
        ->call('unsend', $anasReply->id)
        ->assertForbidden();

    FilamentFeedbackPlugin::get()->allowUnsend(authors: true);
    Livewire::withQueryParams(['post' => $post->id])->test(MyFeedback::class)
        ->call('unsend', $anasReply->id)
        ->assertSee('You unsent a message');

    expect($anasReply->fresh()->isUnsent())->toBeTrue();
});

it('sums up the last 30 days for the team', function (): void {
    $ana = person();
    $hr = person('Hr', admin: true);
    $answered = post($ana, ['mood' => 2]);
    post($ana, ['mood' => -1, 'status' => 'done', 'fix_confirmed' => true]);
    post($ana, ['mood' => -2, 'created_at' => now()->subDays(40)]);

    $this->travel(3)->hours();
    $answered->addReply($hr, 'On it.', fromTeam: true);

    signIn($hr);

    Livewire::test(FeedbackStats::class)
        ->assertSee('Posts, last 30 days')
        ->assertSee('3h')
        ->assertSee('1 confirmed fixed')
        ->assertSee('+0.5')
        ->assertSee('+2.5 vs the 30 days before');
});

it('gives modules an icon, borrowing the navigation icon of the page they map to', function (): void {
    signIn(person());

    expect(FilamentFeedbackPlugin::get()->getModules())->toBe(['dtr' => 'DTR', 'leave' => 'Leave'])
        ->and(FilamentFeedbackPlugin::get()->getModuleIcons())->toBe(['leave' => 'heroicon-o-sun', 'dtr' => 'heroicon-o-calendar-days']);
});

it('tallies moods per module on the board', function (): void {
    $ana = person();
    post($ana, ['mood' => 2, 'modules' => ['dtr', 'leave']]);
    post($ana, ['mood' => -2, 'modules' => ['dtr']]);
    post($ana, ['kind' => 'question', 'mood' => null, 'modules' => ['leave']]);

    signIn(person('Hr', admin: true));

    $board = collect(Livewire::test(FeedbackInbox::class)->instance()->getBoard())->keyBy('slug');

    expect($board['dtr']['total'])->toBe(2)
        ->and($board['dtr']['average'])->toBe(0.0)
        ->and($board['leave']['total'])->toBe(1)
        ->and($board['leave']['label'])->toBe('Leave');
});

it('writes a Chat ping with the kind, modules and mood', function (): void {
    $post = post(person(), ['mood' => -1, 'modules' => ['dtr', 'leave']]);

    expect(GoogleChatPing::text($post, ['dtr' => 'DTR', 'leave' => 'Leave']))
        ->toStartWith('*Feedback* from Ana · DTR, Leave · Frustrated')
        ->toContain('> The DTR page is confusing.');
});
