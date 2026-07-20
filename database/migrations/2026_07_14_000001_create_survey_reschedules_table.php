<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit riwayat reschedule jadwal survey.
 * - source 'admin'   : admin mengubah jadwal yang diajukan (requested_date/time).
 * - source 'manager' : manager surveyor mengubah jadwal penugasan (scheduled_at).
 * Menyimpan nilai lama -> baru agar bisa ditampilkan "dari X ke Y".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_reschedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained('surveys')->cascadeOnDelete();
            $table->string('source', 20);            // admin | manager
            $table->string('field', 20);             // requested | scheduled
            $table->timestamp('old_at')->nullable(); // jadwal lama
            $table->timestamp('new_at')->nullable(); // jadwal baru
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('changed_by_role', 40)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['survey_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_reschedules');
    }
};
