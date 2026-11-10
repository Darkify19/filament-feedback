<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback_posts', function (Blueprint $table): void {
            $table->id();
            // morphs() follows the app's morph key type, so UUID user tables work too.
            $table->morphs('author');
            $table->string('panel')->nullable();
            $table->string('kind')->default('feedback');
            $table->tinyInteger('mood')->nullable();
            $table->json('modules')->nullable();
            $table->text('body');
            $table->string('status')->default('new')->index();
            $table->json('context')->nullable();
            $table->timestamp('author_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('feedback_replies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('feedback_post_id')->constrained('feedback_posts')->cascadeOnDelete();
            $table->morphs('author');
            $table->boolean('is_from_team')->default(false);
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_replies');
        Schema::dropIfExists('feedback_posts');
    }
};
