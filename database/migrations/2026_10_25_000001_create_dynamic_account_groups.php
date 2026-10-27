<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DEFAULT_GROUPS = [
        'PC',
        'INSIP',
        'MITRA SDK',
        'MITRA BSR',
        'PRIBADI',
    ];

    public function up(): void
    {
        Schema::create('account_groups', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name', 100)->unique();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        $this->seedGroups();

        Schema::table('accounts', function (Blueprint $table) {
            $table->foreignId('account_group_id')
                ->nullable()
                ->after('account_group')
                ->constrained('account_groups')
                ->nullOnDelete();
        });

        Schema::table('consultations', function (Blueprint $table) {
            $table->foreignId('account_group_id')
                ->nullable()
                ->after('account_group')
                ->constrained('account_groups')
                ->nullOnDelete();
        });

        $this->backfillGroupIds('accounts');
        $this->backfillGroupIds('consultations');
    }

    public function down(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('account_group_id');
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('account_group_id');
        });

        Schema::dropIfExists('account_groups');
    }

    private function seedGroups(): void
    {
        $codes = collect(self::DEFAULT_GROUPS)
            ->merge(DB::table('accounts')->whereNotNull('account_group')->pluck('account_group'))
            ->merge(DB::table('consultations')->whereNotNull('account_group')->pluck('account_group'))
            ->map(fn ($code) => mb_strtoupper(trim((string) $code)))
            ->filter()
            ->unique()
            ->values();

        $activeCodes = DB::table('accounts')
            ->whereNull('deleted_at')
            ->whereNotNull('account_group')
            ->pluck('account_group')
            ->map(fn ($code) => mb_strtoupper(trim((string) $code)))
            ->all();

        $now = now();
        foreach ($codes as $index => $code) {
            DB::table('account_groups')->insert([
                'code' => $code,
                'name' => $code,
                'sort_order' => ($index + 1) * 10,
                'is_active' => in_array($code, self::DEFAULT_GROUPS, true)
                    || in_array($code, $activeCodes, true),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function backfillGroupIds(string $table): void
    {
        DB::table($table)
            ->whereNotNull('account_group')
            ->select('id', 'account_group')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($table) {
                $groupIds = DB::table('account_groups')
                    ->whereIn('code', $rows->pluck('account_group')->filter()->unique())
                    ->pluck('id', 'code');

                foreach ($rows as $row) {
                    $groupId = $groupIds[mb_strtoupper(trim((string) $row->account_group))] ?? null;
                    if ($groupId) {
                        DB::table($table)->where('id', $row->id)->update(['account_group_id' => $groupId]);
                    }
                }
            });
    }
};
