<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $statusId = DB::table('status_categories')->whereRaw('LOWER(name) = ?', ['sedang survey'])->value('id');
        if (! $statusId) {
            $statusId = DB::table('status_categories')->insertGetId([
                'name' => 'Sedang Survey',
                'css_class' => 'bg-violet-500/10 text-violet-500',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $consultationIds = DB::table('surveys')->where('state', 'in_progress')->pluck('consultation_id');
        if ($consultationIds->isNotEmpty()) {
            DB::table('consultations')->whereIn('id', $consultationIds)->update([
                'status_category_id' => $statusId,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Keep operational statuses and history intact.
    }
};
