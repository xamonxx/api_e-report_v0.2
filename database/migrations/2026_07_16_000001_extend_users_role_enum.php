<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widen users.role to cover every value in App\Enums\UserRole.
 *
 * The survey-team roles (surveyor, manager_surveyor) were added to the PHP enum
 * but the column was still enum('super_admin','admin') from the original users
 * migration. Creating/updating such a user therefore failed with:
 *   SQLSTATE[01000]: Warning: 1265 Data truncated for column 'role' at row 1
 * which surfaced in the UI as "User baru gagal ditambahkan."
 *
 * Raw SQL is used because altering a MySQL ENUM isn't expressible via the
 * schema builder without doctrine/dbal.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE `users` MODIFY `role` ENUM('super_admin','admin','surveyor','manager_surveyor') NOT NULL DEFAULT 'admin'"
        );
    }

    public function down(): void
    {
        // Rows holding the survey roles must be moved out first, otherwise the
        // narrower enum would truncate them to an empty string.
        DB::table('users')
            ->whereIn('role', ['surveyor', 'manager_surveyor'])
            ->update(['role' => 'admin']);

        DB::statement(
            "ALTER TABLE `users` MODIFY `role` ENUM('super_admin','admin') NOT NULL DEFAULT 'admin'"
        );
    }
};
