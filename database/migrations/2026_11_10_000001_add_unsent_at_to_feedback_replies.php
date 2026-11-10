<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Checked first, like the migration before it: safe to re-run after a half-finished deploy.
        if (Schema::hasColumn('feedback_replies', 'unsent_at')) {
            return;
        }

        Schema::table('feedback_replies', function (Blueprint $table): void {
            // An unsent reply keeps its place in the thread as "unsent a message"; its text is cleared.
            $table->timestamp('unsent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('feedback_replies', function (Blueprint $table): void {
            $table->dropColumn('unsent_at');
        });
    }
};
