<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('surveys', 'google_maps_url')) {
            Schema::table('surveys', fn (Blueprint $table) => $table->string('google_maps_url', 2048)->nullable()->after('admin_notes'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('surveys', 'google_maps_url')) {
            Schema::table('surveys', fn (Blueprint $table) => $table->dropColumn('google_maps_url'));
        }
    }
};
