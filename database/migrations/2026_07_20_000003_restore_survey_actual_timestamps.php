<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surveys', function (Blueprint $table) {
            if (! Schema::hasColumn('surveys', 'actual_start_at')) {
                $table->timestamp('actual_start_at')->nullable()->after('scheduled_at');
            }
            if (! Schema::hasColumn('surveys', 'actual_finish_at')) {
                $table->timestamp('actual_finish_at')->nullable()->after('actual_start_at');
            }
        });
    }

    public function down(): void
    {
        // Historical operational timestamps are intentionally retained on rollback.
    }
};
