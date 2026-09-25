<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminder_cron_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            // Satu-satunya tipe di rilis awal ini; kolomnya tetap ada supaya
            // tipe pengingat lain bisa ditambah nanti tanpa migration baru.
            $table->string('type', 40)->default('attendance_reminder');
            $table->time('time_of_day');
            $table->text('message')->nullable();
            $table->boolean('is_active')->default(true);
            // Idempotensi: dibandingkan ke today() supaya job cuma kirim
            // sekali per hari walau command dicek tiap menit.
            $table->date('last_sent_date')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index(['type', 'is_active']);
            $table->index('time_of_day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_cron_jobs');
    }
};
