<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cegah satu lead punya lebih dari satu survey aktif.
 *
 * Sebelumnya SurveyController::store() hanya "cek lalu tulis" tanpa lock, jadi
 * dua permintaan bersamaan bisa lolos berdua. Di data yang ada saat migrasi ini
 * dibuat, tiga lead sudah terlanjur punya dua survey aktif.
 *
 * MySQL tidak punya partial index, jadi dipakai kolom bantu `active_key`:
 * berisi consultation_id selama survey masih aktif, NULL saat cancelled atau
 * soft-deleted. MySQL mengabaikan NULL pada unique index, sehingga survey
 * cancelled boleh menumpuk sebanyak apa pun.
 *
 * Nilainya dijaga otomatis oleh Survey::booted(), bukan oleh controller.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Rapikan duplikat lama: sisakan survey terbaru, sisanya dibatalkan.
        $duplicates = DB::table('surveys')
            ->select('consultation_id')
            ->where('state', '!=', 'cancelled')
            ->whereNull('deleted_at')
            ->groupBy('consultation_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('consultation_id');

        foreach ($duplicates as $consultationId) {
            $keepId = DB::table('surveys')
                ->where('consultation_id', $consultationId)
                ->where('state', '!=', 'cancelled')
                ->whereNull('deleted_at')
                ->orderByDesc('id')
                ->value('id');

            DB::table('surveys')
                ->where('consultation_id', $consultationId)
                ->where('state', '!=', 'cancelled')
                ->whereNull('deleted_at')
                ->where('id', '!=', $keepId)
                ->update([
                    'state' => 'cancelled',
                    'cancelled_at' => now(),
                    'cancellation_reason' => 'Dibatalkan otomatis: satu lead hanya boleh punya satu survey aktif.',
                    'updated_at' => now(),
                ]);
        }

        // 2. Kolom bantu + isi nilainya untuk data yang sudah ada.
        Schema::table('surveys', function (Blueprint $table) {
            $table->unsignedBigInteger('active_key')->nullable()->after('consultation_id');
        });

        DB::table('surveys')
            ->where('state', '!=', 'cancelled')
            ->whereNull('deleted_at')
            ->update(['active_key' => DB::raw('consultation_id')]);

        Schema::table('surveys', function (Blueprint $table) {
            $table->unique('active_key', 'surveys_active_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('surveys', function (Blueprint $table) {
            $table->dropUnique('surveys_active_key_unique');
            $table->dropColumn('active_key');
        });
    }
};
