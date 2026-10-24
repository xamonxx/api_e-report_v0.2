<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Read-only machine credentials for external systems (e.g. NACIRA Sales AI CRM).
     * Deliberately NOT users: they can never pass `auth:sanctum`, so no write route is reachable.
     */
    public function up(): void
    {
        Schema::create('integration_clients', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->char('token_hash', 64)->unique();
            $table->string('token_prefix', 12);
            $table->json('allowed_account_ids');
            $table->json('abilities');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 64)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_clients');
    }
};
