<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultation_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('from_status_id')->nullable()->constrained('status_categories')->nullOnDelete();
            $table->foreignId('to_status_id')->nullable()->constrained('status_categories')->nullOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Analitik velocity menyaring transisi per akun dalam rentang waktu,
            // dan menelusuri kronologi per lead.
            $table->index(['consultation_id', 'created_at']);
            $table->index(['account_id', 'created_at']);
            $table->index(['to_status_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultation_status_histories');
    }
};
