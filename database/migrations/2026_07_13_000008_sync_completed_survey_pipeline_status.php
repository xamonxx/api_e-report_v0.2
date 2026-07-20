<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $aliases = [
            'deal' => 'Selesai/Deal',
            'hold up desain' => 'Hold',
            'pending' => 'Hold',
            'revisi desain' => 'Masih konsultasi',
            'cancel' => 'Cancel',
        ];

        DB::table('surveys as s')
            ->join('survey_statuses as ss', 'ss.id', '=', 's.result_status_id')
            ->where('s.state', 'completed')
            ->whereNotNull('s.result_status_id')
            ->select('s.id', 's.consultation_id', 'ss.name')
            ->orderBy('s.id')
            ->get()
            ->each(function ($survey) use ($aliases) {
                $targetName = $aliases[strtolower(trim($survey->name))] ?? trim($survey->name);
                $targetId = DB::table('status_categories')
                    ->whereRaw('LOWER(name) = ?', [strtolower($targetName)])
                    ->value('id');

                if ($targetId) {
                    DB::table('consultations')
                        ->where('id', $survey->consultation_id)
                        ->update(['status_category_id' => $targetId, 'updated_at' => now()]);
                }
            });
    }

    public function down(): void
    {
        // Consultation statuses may have changed after this backfill.
    }
};
