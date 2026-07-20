<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $statusId = DB::table('status_categories')->whereRaw('LOWER(name) = ?', ['selesai survey'])->value('id');
        if (! $statusId) {
            $statusId = DB::table('status_categories')->insertGetId([
                'name' => 'Selesai Survey',
                'css_class' => 'bg-emerald-500/10 text-emerald-500',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $consultationIds = DB::table('surveys')->where('state', 'completed')->pluck('consultation_id');
        if ($consultationIds->isNotEmpty()) {
            DB::table('consultations')->whereIn('id', $consultationIds)->update([
                'status_category_id' => $statusId,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Keep operational statuses and completed records intact.
    }
};
