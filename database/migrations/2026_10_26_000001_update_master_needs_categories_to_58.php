<?php

use App\Http\Controllers\Api\MasterDataController;
use App\Models\NeedsCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $mergeMap = [
            'Almunium' => 'Alumunium',
            'Aluminium' => 'Alumunium',
            'Apartement' => 'Interior Apartemen',
            'Apartment' => 'Interior Apartemen',
            'Box' => 'Box Elektronik',
            'Jasa Design' => 'Jasa Desain',
            'Kantor' => 'Interior Kantor',
            'Renovasi Rumah' => 'Renovasi Interior',
            'Renovasi' => 'Renovasi Interior',
            'Toko' => 'Interior Toko',
            'Lain-lain' => 'Lain-Lain',
        ];

        $hasPivot = Schema::hasTable('consultation_needs_category');

        foreach ($mergeMap as $oldName => $canonicalName) {
            $source = DB::table('needs_categories')
                ->whereRaw('BINARY name = ?', [$oldName])
                ->first();

            if (! $source || $source->name === $canonicalName) {
                continue;
            }

            $target = DB::table('needs_categories')
                ->whereRaw('BINARY name = ?', [$canonicalName])
                ->first();

            if ($target) {
                $sourceId = $source->id;
                $targetId = $target->id;

                DB::table('consultations')
                    ->where('needs_category_id', $sourceId)
                    ->update(['needs_category_id' => $targetId]);

                if ($hasPivot) {
                    $existingConsultationIds = DB::table('consultation_needs_category')
                        ->where('needs_category_id', $targetId)
                        ->pluck('consultation_id')
                        ->all();

                    if (! empty($existingConsultationIds)) {
                        DB::table('consultation_needs_category')
                            ->where('needs_category_id', $sourceId)
                            ->whereIn('consultation_id', $existingConsultationIds)
                            ->delete();
                    }

                    DB::table('consultation_needs_category')
                        ->where('needs_category_id', $sourceId)
                        ->update(['needs_category_id' => $targetId]);
                }

                DB::table('needs_categories')->where('id', $sourceId)->delete();
            } else {
                DB::table('needs_categories')
                    ->where('id', $source->id)
                    ->update([
                        'name' => $canonicalName,
                        'updated_at' => now(),
                    ]);
            }
        }

        foreach (NeedsCategory::displayNames() as $name) {
            $existing = DB::table('needs_categories')
                ->whereRaw('BINARY name = ?', [$name])
                ->first();

            if ($existing) {
                if ($existing->deleted_at !== null) {
                    DB::table('needs_categories')
                        ->where('id', $existing->id)
                        ->update(['deleted_at' => null, 'updated_at' => now()]);
                }
            } else {
                DB::table('needs_categories')->insert([
                    'name' => $name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        NeedsCategory::whereNotIn('name', NeedsCategory::displayNames())->delete();

        Cache::forget(MasterDataController::CACHE_NEEDS);
        Cache::forget('schema:consultation_needs_category_exists');
    }

    public function down(): void
    {
        Cache::forget(MasterDataController::CACHE_NEEDS);
    }
};
