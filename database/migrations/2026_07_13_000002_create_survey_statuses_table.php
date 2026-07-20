<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Master "Status Survey" â€” status hasil survey yang diisi surveyor
 * (mis. "Hold Up Desain", "Deal", "Revisi Desain"). Meniru pola
 * status_categories: global, reorderable via sort_order, configurable
 * oleh super_admin. Terpisah dari status pipeline marketing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('color', 7)->default('#737c7f');
            $table->string('css_class')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        $now = now();
        $defaults = [
            ['name' => 'Hold Up Desain', 'color' => '#f59e0b'],
            ['name' => 'Deal', 'color' => '#10b981'],
            ['name' => 'Revisi Desain', 'color' => '#6366f1'],
            ['name' => 'Pending', 'color' => '#737c7f'],
            ['name' => 'Cancel', 'color' => '#ef4444'],
        ];

        DB::table('survey_statuses')->insert(
            collect($defaults)->map(fn ($row, $i) => [
                'name' => $row['name'],
                'color' => $row['color'],
                'css_class' => null,
                'sort_order' => $i + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all()
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_statuses');
    }
};
