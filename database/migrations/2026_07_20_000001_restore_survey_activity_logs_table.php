<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('survey_activity_logs')) {
            return;
        }

        Schema::create('survey_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained()->cascadeOnDelete();
            $table->foreignId('consultation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_role', 32)->nullable();
            $table->string('action', 64);
            $table->string('old_status', 64)->nullable();
            $table->string('new_status', 64)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['survey_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_activity_logs');
    }
};
