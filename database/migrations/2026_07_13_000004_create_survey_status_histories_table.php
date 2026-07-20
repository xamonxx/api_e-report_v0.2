<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit transisi state survey (requested â†’ scheduled â†’ completed â†’ ...).
 * Meniru pola consultation_status_histories.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained('surveys')->cascadeOnDelete();
            $table->string('from_state', 20)->nullable();
            $table->string('to_state', 20);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['survey_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_status_histories');
    }
};
