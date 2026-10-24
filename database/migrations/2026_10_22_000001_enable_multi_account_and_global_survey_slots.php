<?php

use App\Models\Survey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('account_user')
            ->select('user_id', 'account_id', DB::raw('MIN(id) as keep_id'))
            ->groupBy('user_id', 'account_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->each(fn ($row) => DB::table('account_user')
                ->where('user_id', $row->user_id)
                ->where('account_id', $row->account_id)
                ->where('id', '!=', $row->keep_id)
                ->delete());

        Schema::table('account_user', function (Blueprint $table) {
            $table->unique(['user_id', 'account_id'], 'account_user_membership_unique');
        });

        Schema::table('surveys', function (Blueprint $table) {
            $table->string('schedule_slot_key', 100)->nullable()->after('scheduled_at');
            $table->unique('schedule_slot_key', 'surveys_schedule_slot_unique');
        });

        Survey::query()->withTrashed()
            ->whereNotNull('surveyor_id')
            ->whereNotNull('scheduled_at')
            ->whereIn('state', [Survey::STATE_SCHEDULED, Survey::STATE_IN_PROGRESS])
            ->orderBy('id')
            ->each(function (Survey $survey) {
                $key = $survey->surveyor_id.'|'.$survey->scheduled_at->format('Y-m-d H:i:s');
                if (DB::table('surveys')->where('schedule_slot_key', $key)->exists()) {
                    throw new RuntimeException("Bentrok jadwal lama ditemukan pada slot {$key}; rapikan data sebelum migration.");
                }
                DB::table('surveys')->where('id', $survey->id)->update(['schedule_slot_key' => $key]);
            });
    }

    public function down(): void
    {
        Schema::table('surveys', function (Blueprint $table) {
            $table->dropUnique('surveys_schedule_slot_unique');
            $table->dropColumn('schedule_slot_key');
        });
        Schema::table('account_user', fn (Blueprint $table) => $table->dropUnique('account_user_membership_unique'));
    }
};
