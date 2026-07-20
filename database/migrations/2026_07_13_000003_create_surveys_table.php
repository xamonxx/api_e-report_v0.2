<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dimensi survey â€” terpisah dari status pipeline lead. Satu consultation bisa
 * punya banyak survey historis (mis. re-survey), umumnya satu yang aktif.
 *
 * Lifecycle state: requested â†’ scheduled â†’ completed (atau cancelled).
 * - requested : admin "Ajukan Survey" â†’ masuk antrian Manager Surveyor.
 * - scheduled : Manager pilih surveyor + jadwal â†’ "Masuk Survey".
 * - completed : Surveyor isi result_status (mis. "Hold Up Desain") + catatan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surveys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultation_id')->constrained('consultations')->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();

            $table->string('state', 20)->default('requested');

            // Pengajuan (oleh admin/sales)
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->nullable();

            // Penugasan (oleh manager surveyor)
            $table->foreignId('surveyor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('scheduled_at')->nullable();   // Tgl. Survey
            $table->text('location_notes')->nullable();

            // Hasil (oleh surveyor)
            $table->foreignId('result_status_id')->nullable()->constrained('survey_statuses')->nullOnDelete();
            $table->text('result_notes')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['state', 'scheduled_at']);
            $table->index(['surveyor_id', 'state']);
            $table->index(['account_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surveys');
    }
};
