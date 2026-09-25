<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot grup akun (PC/NPP1/NPP2) pada saat konsultasi dibuat.
 *
 * Berbeda dari accounts.account_group yang bisa berubah kapan saja (akun
 * pindah grup), kolom ini harus tetap sesuai kondisi akun pada saat lead
 * tercatat -- karenanya diisi eksplisit di setiap titik pembuatan
 * Consultation, tidak pernah diturunkan lewat join ke accounts saat dibaca.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            $table->string('account_group', 10)->nullable()->after('account_id');
        });

        $this->backfillFromCurrentAccount();

        Schema::table('consultations', function (Blueprint $table) {
            $table->index('account_group', 'consultations_account_group_index');
        });
    }

    public function down(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            $table->dropIndex('consultations_account_group_index');
            $table->dropColumn('account_group');
        });
    }

    /**
     * Backfill best-effort untuk baris lama: diambil dari account_group akun
     * SAAT INI, bukan histori sebenarnya (histori titik-waktu tidak bisa
     * dipulihkan). Ini keterbatasan yang disengaja/diterima, bukan bug.
     */
    private function backfillFromCurrentAccount(): void
    {
        DB::table('consultations')
            ->join('accounts', 'accounts.id', '=', 'consultations.account_id')
            ->whereNull('consultations.account_group')
            ->orderBy('consultations.id')
            ->select('consultations.id', 'accounts.account_group')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('consultations')
                        ->where('id', $row->id)
                        ->update(['account_group' => $row->account_group]);
                }
            }, 'consultations.id', 'id');
    }
};
