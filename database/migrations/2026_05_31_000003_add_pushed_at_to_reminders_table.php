<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tracks when a due-reminder push was sent, so the scheduled command only
     * pushes each reminder once.
     */
    public function up(): void
    {
        Schema::table('reminders', function (Blueprint $table) {
            $table->timestamp('pushed_at')->nullable()->after('is_read');
            $table->index(['pushed_at', 'remind_at']);
        });
    }

    public function down(): void
    {
        Schema::table('reminders', function (Blueprint $table) {
            $table->dropIndex(['pushed_at', 'remind_at']);
            $table->dropColumn('pushed_at');
        });
    }
};
