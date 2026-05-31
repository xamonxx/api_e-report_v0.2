<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * consultation_date / created_at are already indexed, and account-scoped
     * date/sort patterns are covered by the compound indexes from
     * 2026_05_21_000001. The one remaining gap is a STANDALONE updated_at index:
     * the consultations list defaults to ORDER BY updated_at DESC, and for the
     * super-admin (unscoped, no account_id filter) the existing
     * idx_consult_acct_updated_del (leading column account_id) can't be used,
     * forcing a filesort on every page. Add it.
     */
    public function up(): void
    {
        if ($this->hasIndex('consultations', 'consultations_updated_at_index')) {
            return;
        }

        Schema::table('consultations', function (Blueprint $table) {
            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        if (! $this->hasIndex('consultations', 'consultations_updated_at_index')) {
            return;
        }

        Schema::table('consultations', function (Blueprint $table) {
            $table->dropIndex(['updated_at']);
        });
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if ($index['name'] === $indexName) {
                return true;
            }
        }

        return false;
    }
};
