<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen `users.role` so the Surveyor & Manager Surveyor roles can persist.
 *
 * The base migration defined `role` as enum('super_admin','admin'), which
 * rejects the `surveyor` / `manager_surveyor` values already declared in
 * App\Enums\UserRole. We convert the column to a plain string: role validity
 * is already enforced at the application layer (UserRole cast + Enum rule in
 * MasterDataController), so the DB-level enum is redundant and brittle â€” a
 * string means future roles need no further migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 32)->default('admin')->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['super_admin', 'admin'])->default('admin')->change();
        });
    }
};
