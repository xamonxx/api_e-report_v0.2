<?php

use App\Http\Controllers\Api\MasterDataController;
use App\Models\NeedsCategory;
use App\Support\PendingConfirmation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Kategori kebutuhan default berganti nama: "Belum ada konfirmasi" menjadi
 * "Tidak konfirmasi".
 *
 * Baris di-RENAME, bukan dibuat baru, supaya id-nya tetap dan relasi pada
 * consultation_needs_category serta kolom needs_category_id tidak putus.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rename(PendingConfirmation::LEGACY_LABEL, NeedsCategory::PENDING_LABEL);
    }

    public function down(): void
    {
        $this->rename(NeedsCategory::PENDING_LABEL, PendingConfirmation::LEGACY_LABEL);
    }

    private function rename(string $from, string $to): void
    {
        $target = DB::table('needs_categories')->where('name', $to)->first();

        if ($target) {
            // Nama tujuan sudah dipakai baris lain - hentikan agar tidak
            // melanggar unique index atau memecah data ke dua kategori.
            return;
        }

        DB::table('needs_categories')
            ->where('name', $from)
            ->update(['name' => $to, 'updated_at' => now()]);

        // Daftar kategori di-cache 6 jam oleh MasterDataController. Tanpa
        // dibersihkan di sini, form dan template masih menyajikan nama lama
        // sampai cache kedaluwarsa sendiri.
        Cache::forget(MasterDataController::CACHE_NEEDS);
    }
};
