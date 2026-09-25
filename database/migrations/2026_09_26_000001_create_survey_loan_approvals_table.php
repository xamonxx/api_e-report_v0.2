<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_loan_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained()->cascadeOnDelete();
            $table->foreignId('surveyor_id')->constrained('users')->cascadeOnDelete();
            // Snapshot tim saat persetujuan dibuat - tim akun/surveyor bisa
            // berubah belakangan, tapi izin ini tetap dibaca apa adanya saat itu.
            $table->string('borrower_team', 1);
            $table->string('lender_team', 1);
            $table->foreignId('approved_by')->constrained('users');
            $table->timestamp('approved_at');
            $table->text('reason');
            $table->foreignId('revoked_by')->nullable()->constrained('users');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            // Cek kelayakan & visibilitas selalu query "izin aktif untuk survey
            // + surveyor ini" - kombinasi ini yang perlu cepat, bukan per kolom.
            $table->index(['survey_id', 'surveyor_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_loan_approvals');
    }
};
