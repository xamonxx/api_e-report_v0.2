<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Consultations: main listing query filter ──────────────
        // Used by: GET /consultations (account + status + not-deleted)
        Schema::table('consultations', function (Blueprint $table) {
            // Drop standalone indexes that are now covered by compound ones
            if ($this->hasIndex('consultations', 'consultations_phone_index')) {
                $table->dropIndex('consultations_phone_index');
            }

            // Primary listing filter: account + status + soft-delete check
            $table->index(['account_id', 'status_category_id', 'deleted_at'], 'idx_consult_acct_status_del');

            // Date range scans for dashboard / analytics
            $table->index(['account_id', 'consultation_date', 'deleted_at'], 'idx_consult_acct_date_del');

            // Sort: updated_at DESC (listing default order)
            $table->index(['account_id', 'updated_at', 'deleted_at'], 'idx_consult_acct_updated_del');
        });

        // ── Consultation Notes: notification lookups ──────────────
        Schema::table('consultation_notes', function (Blueprint $table) {
            $table->index(['consultation_id', 'is_read', 'created_at'], 'idx_notes_consult_read_created');
        });

        // ── Reminders: upcoming reminders query ──────────────────
        Schema::table('reminders', function (Blueprint $table) {
            $table->index(['is_read', 'remind_at'], 'idx_reminders_read_remind');
        });

        // ── Report Attendances: daily check ──────────────────────
        Schema::table('report_attendances', function (Blueprint $table) {
            $table->index(['user_id', 'report_date'], 'idx_attendance_user_date');
        });
    }

    public function down(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            $table->dropIndex('idx_consult_acct_status_del');
            $table->dropIndex('idx_consult_acct_date_del');
            $table->dropIndex('idx_consult_acct_updated_del');
        });

        Schema::table('consultation_notes', function (Blueprint $table) {
            $table->dropIndex('idx_notes_consult_read_created');
        });

        Schema::table('reminders', function (Blueprint $table) {
            $table->dropIndex('idx_reminders_read_remind');
        });

        Schema::table('report_attendances', function (Blueprint $table) {
            $table->dropIndex('idx_attendance_user_date');
        });
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        $indexes = Schema::getIndexes($table);
        foreach ($indexes as $index) {
            if ($index['name'] === $indexName) {
                return true;
            }
        }
        return false;
    }
};
