@php
    use CarlJanzell\FilamentFeedback\Enums\FeedbackStatus;
    use Illuminate\Support\Str;
@endphp

<x-filament-panels::page>
    @if ($posts->isEmpty())
        <div class="ffb-empty">
            <x-filament::icon icon="heroicon-o-chat-bubble-left-right" class="ffb-icon ffb-icon-lg" />
            <p><strong>Nothing sent yet.</strong></p>
            <p class="ffb-muted">Press <strong>Feedback</strong> at the bottom of any page to tell the team what works and what doesn't. Their replies show up here.</p>
        </div>
    @else
        <div class="ffb-page" style="--ffb-accent: {{ \CarlJanzell\FilamentFeedback\Support\Compat::color('primary') }}; --ffb-success: {{ \CarlJanzell\FilamentFeedback\Support\Compat::color('success', 600) }}">
            <nav class="ffb-list" aria-label="Your posts">
                @foreach ($posts as $item)
                    <button
                        type="button"
                        wire:key="ffb-item-{{ $item->getKey() }}"
                        wire:click="open({{ $item->getKey() }})"
                        @class(['ffb-item', 'ffb-item-active' => $thread?->is($item)])
                        aria-current="{{ $thread?->is($item) ? 'true' : 'false' }}"
                    >
                        <span class="ffb-item-top">
                            <x-filament::badge :color="$item->status->getColor()" size="sm">{{ $item->status->getLabel() }}</x-filament::badge>
                            <span class="ffb-muted ffb-small">{{ $item->kind->shortLabel() }} · {{ $item->created_at->diffForHumans(short: true) }}</span>
                            @if (in_array($item->getKey(), $unreadIds, true))
                                <span class="ffb-dot" title="New reply"></span>
                            @endif
                        </span>
                        <span class="ffb-item-text">{{ Str::limit(Str::squish($item->body), 120) }}</span>
                        @if ($item->modules || $item->replies_count)
                            <span class="ffb-item-meta ffb-small ffb-muted">
                                @include('filament-feedback::module-chips', ['post' => $item])
                                @if ($item->replies_count)
                                    <span class="ffb-item-replies">
                                        <x-filament::icon icon="heroicon-o-chat-bubble-oval-left" class="ffb-icon" />{{ $item->replies_count }}
                                    </span>
                                @endif
                            </span>
                        @endif
                    </button>
                @endforeach
            </nav>

            @if ($thread)
                <section class="ffb-view" wire:key="ffb-view-{{ $thread->getKey() }}" aria-label="Thread">
                    <header class="ffb-view-head">
                        <div class="ffb-chips">
                            <x-filament::badge :color="$thread->status->getColor()" :icon="$thread->status->getIcon()">{{ $thread->status->getLabel() }}</x-filament::badge>
                            @if ($thread->mood)
                                <x-filament::badge :color="$thread->mood->getColor()" :icon="$thread->mood->getIcon()">{{ $thread->mood->getLabel() }}</x-filament::badge>
                            @endif
                            @include('filament-feedback::module-chips', ['post' => $thread])
                            @if (filled($thread->context['url'] ?? null))
                                <span class="ffb-small ffb-muted">Sent from <a href="{{ $thread->context['url'] }}" class="ffb-link">{{ Str::limit(parse_url($thread->context['url'], PHP_URL_PATH) ?: '/', 40) }}</a></span>
                            @endif
                        </div>
                        @include('filament-feedback::timeline', ['post' => $thread])
                    </header>

                    @if ($thread->status === FeedbackStatus::Done && $thread->fix_confirmed === null)
                        <div class="ffb-fix" role="group" aria-label="Did this fix it?">
                            <span><strong>The team marked this done.</strong> Did it fix it for you?</span>
                            <span class="ffb-fix-actions">
                                <x-filament::button size="sm" color="success" icon="heroicon-m-check" wire:click="confirmFix(true)">Yes, fixed</x-filament::button>
                                <x-filament::button size="sm" color="gray" wire:click="confirmFix(false)">No, still broken</x-filament::button>
                            </span>
                        </div>
                    @elseif ($thread->fix_confirmed)
                        <p class="ffb-small ffb-muted ffb-fixed">
                            <x-filament::icon icon="heroicon-o-check-circle" class="ffb-icon" /> You confirmed the fix.
                        </p>
                    @endif

                    <div class="ffb-thread">
                        <x-filament-feedback::message
                            wire:key="ffb-msg-post-{{ $thread->getKey() }}"
                            name="You"
                            :time="$thread->created_at"
                            :body="$thread->body"
                            :reactable="$thread"
                            :viewer="$viewer"
                        />

                        @foreach ($thread->replies as $reply)
                            <x-filament-feedback::message
                                wire:key="ffb-msg-reply-{{ $reply->getKey() }}"
                                :name="$reply->is_from_team ? $reply->authorName() : 'You'"
                                :time="$reply->created_at"
                                :body="$reply->body"
                                :team="$reply->is_from_team"
                                :reactable="$reply"
                                :viewer="$viewer"
                            />
                        @endforeach
                    </div>

                    <form class="ffb-composer" wire:submit="reply">
                        <x-filament::input.wrapper class="ffb-composer-input" :valid="! $errors->has('draft')">
                            <textarea
                                wire:model="draft"
                                rows="2"
                                class="ffb-textarea"
                                aria-label="Reply to the team"
                                placeholder="Reply to the team…"
                                x-on:keydown.meta.enter.prevent="$wire.reply()"
                                x-on:keydown.ctrl.enter.prevent="$wire.reply()"
                            ></textarea>
                        </x-filament::input.wrapper>
                        <x-filament::button type="submit" icon="heroicon-m-paper-airplane" wire:target="reply">Reply</x-filament::button>
                    </form>
                    @error('draft')
                        <p class="ffb-error">{{ $message }}</p>
                    @enderror
                </section>
            @endif
        </div>
    @endif
</x-filament-panels::page>
