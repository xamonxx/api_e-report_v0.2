<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Satu baris global (key selalu 'default') - lebih sederhana daripada
        // tabel key-value generik untuk kebutuhan rilis pertama ini (cuma 1
        // setting, bukan banyak).
        Schema::create('survey_reminder_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique()->default('default');
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('lead_minutes')->default(300);
            $table->text('message_template')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Naik tiap kali jadwal final/surveyor berubah atau penugasan
        // dilepas - dipakai buat membedakan versi penugasan mana yang sudah
        // "diberi tahu", supaya reschedule/reassign tidak mengirim ulang
        // pengingat basi milik versi lama tapi juga tidak melewatkan versi
        // baru yang belum sempat dikirimi.
        Schema::table('surveys', function (Blueprint $table) {
            $table->unsignedInteger('schedule_revision')->default(0)->after('scheduled_at');
        });

        Schema::create('survey_reminder_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('schedule_revision');
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('scheduled_at_snapshot');
            $table->unsignedInteger('lead_minutes');
            $table->timestamp('due_at');
            // pending -> processing -> notified | cancelled | expired | failed
            $table->string('status', 20)->default('pending');
            $table->timestamp('claimed_at')->nullable();
            $table->string('lease_token', 40)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->foreignId('notification_id')->nullable()->constrained('survey_notifications')->nullOnDelete();
            $table->timestamp('push_attempted_at')->nullable();
            // not_attempted | skipped_no_subscription | attempted | failed | unknown
            $table->string('push_status', 25)->default('not_attempted');
            $table->string('last_error_code', 60)->nullable();
            $table->timestamps();

            // Satu pengingat logis per (survey, versi penugasan, penerima) -
            // replan (assign/reschedule) membatalkan baris versi lama lalu
            // membuat baris baru untuk revision baru, bukan menimpa baris ini.
            $table->unique(['survey_id', 'schedule_revision', 'recipient_id'], 'survey_reminder_deliveries_unique_version');
            $table->index(['status', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_reminder_deliveries');
        Schema::table('surveys', function (Blueprint $table) {
            $table->dropColumn('schedule_revision');
        });
        Schema::dropIfExists('survey_reminder_settings');
    }
};
