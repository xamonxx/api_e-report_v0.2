<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surveys', function (Blueprint $table) {
            $table->date('requested_date')->nullable()->after('requested_at');
            $table->time('requested_time')->nullable()->after('requested_date');
            $table->string('requested_item', 500)->nullable()->after('requested_time');
            $table->text('admin_notes')->nullable()->after('location_notes');
            $table->string('google_maps_url', 2048)->nullable()->after('admin_notes');
            $table->text('manager_notes')->nullable()->after('google_maps_url');
            $table->timestamp('actual_start_at')->nullable()->after('completed_at');
            $table->timestamp('actual_finish_at')->nullable()->after('actual_start_at');
            $table->text('location_condition')->nullable()->after('result_notes');
            $table->text('customer_notes')->nullable()->after('location_condition');
            $table->text('obstacles')->nullable()->after('customer_notes');
            $table->text('recommendations')->nullable()->after('obstacles');
            $table->text('additional_notes')->nullable()->after('recommendations');
            $table->timestamp('cancelled_at')->nullable()->after('additional_notes');
            $table->text('cancellation_reason')->nullable()->after('cancelled_at');
            $table->index(['surveyor_id', 'scheduled_at', 'state']);
        });
    }

    public function down(): void
    {
        Schema::table('surveys', function (Blueprint $table) {
            $table->dropIndex(['surveyor_id', 'scheduled_at', 'state']);
            $table->dropColumn([
                'requested_date', 'requested_time', 'requested_item', 'admin_notes',
                'google_maps_url', 'manager_notes', 'actual_start_at', 'actual_finish_at',
                'location_condition', 'customer_notes', 'obstacles', 'recommendations',
                'additional_notes', 'cancelled_at', 'cancellation_reason',
            ]);
        });
    }
};
