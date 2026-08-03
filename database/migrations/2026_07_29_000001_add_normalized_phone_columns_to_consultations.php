<?php

use App\Models\Consultation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            $table->string('phone_normalized', 32)->nullable()->after('phone')->index();
            $table->string('emergency_phone_normalized', 32)->nullable()->after('emergency_phone')->index();
        });

        Consultation::withTrashed()
            ->select(['id', 'phone', 'emergency_phone'])
            ->orderBy('id')
            ->chunkById(500, function ($consultations) {
                foreach ($consultations as $consultation) {
                    DB::table('consultations')
                        ->where('id', $consultation->id)
                        ->update([
                            'phone_normalized' => Consultation::normalizeLeadPhone($consultation->phone),
                            'emergency_phone_normalized' => Consultation::normalizeLeadPhone($consultation->emergency_phone),
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            $table->dropColumn(['phone_normalized', 'emergency_phone_normalized']);
        });
    }
};
