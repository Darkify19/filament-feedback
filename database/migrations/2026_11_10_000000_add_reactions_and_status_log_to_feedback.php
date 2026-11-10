<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Each step checks first: MySQL can't roll back DDL, so a run that failed halfway (v0.2.0–0.4.0
 * shipped a unique index over two 255-char morph types, past MySQL's 3072-byte key limit) left
 * the columns and the table behind without recording the migration. Re-running finishes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feedback_posts', function (Blueprint $table): void {
            // [{status, at}, …] for every status change, so the author sees Seen → Planned → Done with times.
            if (! Schema::hasColumn('feedback_posts', 'status_log')) {
                $table->json('status_log')->nullable();
            }

            // The author's answer to "Did this fix it?" once a post is Done: null until they answer.
            if (! Schema::hasColumn('feedback_posts', 'fix_confirmed')) {
                $table->boolean('fix_confirmed')->nullable();
            }
        });

        if (! Schema::hasTable('feedback_reactions')) {
            Schema::create('feedback_reactions', function (Blueprint $table): void {
                $table->id();
                // A post or a reply.
                $table->morphs('reactable');
                $table->morphs('author');
                $table->string('reaction', 32);
                $table->timestamps();
                // No unique index: toggleReaction() checks first, and a duplicate from a double
                // click only counts twice. A unique key over both morph types is too long for MySQL.
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_reactions');

        Schema::table('feedback_posts', function (Blueprint $table): void {
            $table->dropColumn(['status_log', 'fix_confirmed']);
        });
    }
};
