<?php

use App\Models\NeedsCategory;
use App\Support\PendingConfirmation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach ([PendingConfirmation::LABEL, NeedsCategory::OTHER_OPTION_LABEL] as $name) {
            $exists = DB::table('needs_categories')
                ->where('name', $name)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('needs_categories')->insert([
                'name' => $name,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('needs_categories')
            ->whereIn('name', [PendingConfirmation::LABEL, NeedsCategory::OTHER_OPTION_LABEL])
            ->delete();
    }
};
