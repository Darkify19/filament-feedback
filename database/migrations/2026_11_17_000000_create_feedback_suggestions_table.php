<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Checked first, like the migrations before it: safe to re-run after a half-finished deploy.
        if (Schema::hasTable('feedback_suggestions')) {
            return;
        }

        // Drafted replies a triager sends, edits or discards. Rows stay after they're decided,
        // so a drafting tool can read how its drafts ended.
        Schema::create('feedback_suggestions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('feedback_post_id')->constrained('feedback_posts')->cascadeOnDelete();
            // Null: a status-only suggestion, e.g. close a test post without replying.
            $table->text('body')->nullable();
            // A FeedbackStatus value; null leaves the status as it is.
            $table->string('status', 20)->nullable();
            // For triagers only: why, what was checked, what to do by hand.
            $table->text('note')->nullable();
            $table->json('sources')->nullable();
            $table->string('confidence', 10)->default('medium');
            $table->string('drafted_by', 100)->nullable();
            // The post's latest reply when drafted (null: none yet). A newer reply makes it stale.
            $table->unsignedBigInteger('for_reply_id')->nullable();
            // No unique "one pending per post" index (morph keys are too long for MySQL);
            // ImportSuggestions supersedes the older pending draft instead.
            $table->string('outcome', 20)->default('pending')->index();
            $table->unsignedBigInteger('final_reply_id')->nullable();
            $table->nullableMorphs('decided_by');
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_suggestions');
    }
};
