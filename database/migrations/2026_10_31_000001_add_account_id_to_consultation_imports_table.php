<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Akun aktif admin saat upload. Import diproses di worker antrean yang tidak
     * punya request (X-Account-Id), jadi tanpa kolom ini baris masuk ke akun primer.
     */
    public function up(): void
    {
        if (Schema::hasColumn('consultation_imports', 'account_id')) {
            return;
        }

        Schema::table('consultation_imports', function (Blueprint $table) {
            $table->foreignId('account_id')->nullable()->after('user_id')
                ->constrained('accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('consultation_imports', 'account_id')) {
            return;
        }

        Schema::table('consultation_imports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('account_id');
        });
    }
};
