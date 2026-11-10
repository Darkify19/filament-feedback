<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feedback_posts', function (Blueprint $table): void {
            // [{status, at}, …] for every status change, so the author sees Seen → Planned → Done with times.
            $table->json('status_log')->nullable();
            // The author's answer to "Did this fix it?" once a post is Done: null until they answer.
            $table->boolean('fix_confirmed')->nullable();
        });

        Schema::create('feedback_reactions', function (Blueprint $table): void {
            $table->id();
            // A post or a reply.
            $table->morphs('reactable');
            $table->morphs('author');
            $table->string('reaction');
            $table->timestamps();

            $table->unique(['reactable_type', 'reactable_id', 'author_type', 'author_id', 'reaction'], 'feedback_reactions_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_reactions');

        Schema::table('feedback_posts', function (Blueprint $table): void {
            $table->dropColumn(['status_log', 'fix_confirmed']);
        });
    }
};
