<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_attendance_id')->constrained('report_attendances')->cascadeOnDelete();
            $table->string('title', 160);
            $table->text('message');
            $table->string('admin_name', 120);
            $table->string('account_name', 160)->nullable();
            $table->date('report_date');
            $table->string('report_category', 32);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'report_attendance_id'], 'attendance_notif_user_report_unique');
            $table->index(['user_id', 'read_at']);
            $table->index(['report_date', 'report_category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_notifications');
    }
};
