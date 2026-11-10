<?php

use CarlJanzell\FilamentFeedback\Models\FeedbackPost;
use CarlJanzell\FilamentFeedback\Tests\Fixtures\Models\User;
use CarlJanzell\FilamentFeedback\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

function person(string $name = 'Ana', bool $admin = false): User
{
    return User::create(['name' => $name, 'email' => strtolower($name).'@example.com', 'password' => 'secret', 'is_admin' => $admin]);
}

function signIn(User $user): User
{
    test()->actingAs($user);

    return $user;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function post(User $author, array $attributes = []): FeedbackPost
{
    $post = new FeedbackPost(['panel' => 'testing', 'kind' => 'feedback', 'mood' => -1, 'modules' => ['dtr'], 'body' => 'The DTR page is confusing.', 'author_seen_at' => now(), ...$attributes]);
    $post->author()->associate($author);
    $post->save();

    return $post;
}
