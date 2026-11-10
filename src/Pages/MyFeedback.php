<?php

namespace CarlJanzell\FilamentFeedback\Pages;

use CarlJanzell\FilamentFeedback\Enums\FeedbackStatus;
use CarlJanzell\FilamentFeedback\Enums\Reaction;
use CarlJanzell\FilamentFeedback\FilamentFeedbackPlugin;
use CarlJanzell\FilamentFeedback\Models\FeedbackPost;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Url;

/**
 * The author's side: every post they sent, one thread open at a time, with replies,
 * reactions and the status timeline. Registered on panels with the Feedback button.
 * Posting stays in the button's sheet, on the page the post is about.
 */
class MyFeedback extends Page
{
    protected static ?string $slug = 'my-feedback';

    /** The open thread; notifications link straight to it with ?post=. */
    #[Url]
    public ?int $post = null;

    public string $draft = '';

    public function mount(): void
    {
        $this->open($this->post);
    }

    /**
     * Shares one blade view across Filament 3–5 (3 declares `$view` static, 4 and 5 don't).
     */
    public function getView(): string
    {
        return 'filament-feedback::my-feedback';
    }

    public function getTitle(): string
    {
        return 'My feedback';
    }

    public static function getNavigationLabel(): string
    {
        return 'My feedback';
    }

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-chat-bubble-left-right';
    }

    public static function getNavigationGroup(): ?string
    {
        return FilamentFeedbackPlugin::get()->getNavigationGroup();
    }

    public static function getNavigationSort(): ?int
    {
        return 90;
    }

    public static function getNavigationBadge(): ?string
    {
        $user = Filament::auth()->user();
        $unread = $user ? FeedbackPost::query()->ownedBy($user)->withUnreadReplies()->count() : 0;

        return $unread > 0 ? (string) $unread : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            // The sheet lives in the launcher on every page; this just opens it.
            Action::make('newPost')
                ->label('New post')
                ->icon('heroicon-o-plus')
                ->alpineClickHandler("\$dispatch('ffb-open-sheet')"),
        ];
    }

    /**
     * Opens a thread (the newest one when the id isn't the viewer's) and marks it read.
     */
    public function open(?int $postId): void
    {
        $post = $postId ? $this->posts()->find($postId) : null;
        $post ??= $this->posts()->first();

        $this->post = $post?->getKey();
        $this->draft = '';

        if ($post) {
            FeedbackPost::query()->whereKey($post->getKey())->toBase()->update(['author_seen_at' => now()]);
        }
    }

    public function reply(): void
    {
        $this->validate(['draft' => ['required', 'max:5000']], [], ['draft' => 'reply']);

        $post = $this->selected();
        abort_if($post === null, 404);

        $post->addReply($this->user(), trim($this->draft), fromTeam: false);

        // Back in front of the team: a reply on a closed post means it isn't closed.
        $post->update(['status' => FeedbackStatus::New, 'author_seen_at' => now(), 'fix_confirmed' => null]);

        $this->draft = '';

        Notification::make()->title('Reply sent')->success()->send();
    }

    /**
     * The author's answer to "Did this fix it?". A no reopens the post with a note to the team.
     */
    public function confirmFix(bool $fixed): void
    {
        $post = $this->selected();
        abort_if($post === null || $post->status !== FeedbackStatus::Done, 404);

        if ($fixed) {
            $post->update(['fix_confirmed' => true]);
            Notification::make()->title('Thanks for confirming')->success()->send();

            return;
        }

        $post->addReply($this->user(), 'This still isn\'t fixed for me.', fromTeam: false);
        $post->update(['status' => FeedbackStatus::New, 'fix_confirmed' => false, 'author_seen_at' => now()]);
        Notification::make()->title('Reopened. The team will take another look.')->send();
    }

    /**
     * @param  'post'|'reply'  $type
     */
    public function react(string $type, int $id, string $reaction): void
    {
        $post = $this->selected();
        abort_if($post === null, 404);

        $target = $type === 'post' ? ($post->getKey() === $id ? $post : null) : $post->replies()->find($id);
        abort_if($target === null || $target->isWrittenBy($this->user()), 404);

        $target->toggleReaction($this->user(), Reaction::from($reaction));
    }

    public function unsend(int $replyId): void
    {
        $reply = $this->selected()?->replies()->find($replyId);
        abort_unless($reply && ! $reply->is_from_team && $reply->isWrittenBy($this->user()) && FilamentFeedbackPlugin::get()->authorsCanUnsend(), 403);

        $reply->unsend();
    }

    public function selected(): ?FeedbackPost
    {
        return $this->post ? $this->posts()->find($this->post) : null;
    }

    /**
     * @return Builder<FeedbackPost>
     */
    protected function posts()
    {
        return FeedbackPost::query()->ownedBy($this->user())->latest()->latest('id');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $plugin = FilamentFeedbackPlugin::get();
        /** @var Collection<int, FeedbackPost> $posts */
        $posts = $this->posts()->withCount('replies')->limit(50)->get();

        return [
            'posts' => $posts,
            'unreadIds' => $this->posts()->withUnreadReplies()->pluck('id')->all(),
            'thread' => $this->selected()?->load(['reactions', 'replies.author', 'replies.reactions']),
            'modules' => $plugin->getModules(),
            'moduleIcons' => $plugin->getModuleIcons(),
            'viewer' => $this->user(),
            'canUnsend' => $plugin->authorsCanUnsend(),
        ];
    }

    protected function user(): Model
    {
        /** @var Model */
        return Filament::auth()->user();
    }
}
