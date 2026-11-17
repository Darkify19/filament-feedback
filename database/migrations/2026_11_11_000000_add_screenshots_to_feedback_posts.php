<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Checked first, like the migrations before it: safe to re-run after a half-finished deploy.
        if (Schema::hasColumn('feedback_posts', 'screenshots')) {
            return;
        }

        Schema::table('feedback_posts', function (Blueprint $table): void {
            // [{disk, path}, …] for the images attached in the sheet. The disk is kept per file,
            // so changing `screenshots(disk: …)` later doesn't break older posts.
            $table->json('screenshots')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('feedback_posts', function (Blueprint $table): void {
            $table->dropColumn('screenshots');
        });
    }
};
