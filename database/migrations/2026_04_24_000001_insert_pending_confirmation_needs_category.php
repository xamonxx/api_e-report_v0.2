<?php

use App\Support\PendingConfirmation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('needs_categories')
            ->where('name', PendingConfirmation::LABEL)
            ->exists();

        if (! $exists) {
            DB::table('needs_categories')->insert([
                'name' => PendingConfirmation::LABEL,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('needs_categories')
            ->where('name', PendingConfirmation::LABEL)
            ->delete();
    }
};
