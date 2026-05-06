<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultation_imports', function (Blueprint $table) {
            $table->unsignedInteger('updated_count')->default(0)->after('success_count');
        });
    }

    public function down(): void
    {
        Schema::table('consultation_imports', function (Blueprint $table) {
            $table->dropColumn('updated_count');
        });
    }
};
