<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bug_reports', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_code', 16)->unique();
            $table->text('description');
            $table->string('page_url', 2048)->nullable();
            $table->string('reporter_email')->nullable();
            // Stored relative disk paths for up to 3 screenshots (JSON array).
            $table->json('image_paths')->nullable();
            $table->string('status', 20)->default('open');
            // Submitter context — nullable because the form is reachable by guests.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bug_reports');
    }
};
