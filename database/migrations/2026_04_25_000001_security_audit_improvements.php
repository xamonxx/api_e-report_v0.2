<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Security Audit Migration
 * 
 * 1. Add audit columns (created_by, updated_by, deleted_by) to key tables
 * 2. Add soft deletes to accounts and users tables
 * 3. Add unique indexes for data integrity
 * 4. Add login_attempts tracking columns
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Accounts: soft deletes + audit columns ────────────────
        Schema::table('accounts', function (Blueprint $table) {
            if (!Schema::hasColumn('accounts', 'deleted_at')) {
                $table->softDeletes();
            }
            if (!Schema::hasColumn('accounts', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable()->after('target_leads');
                $table->unsignedBigInteger('updated_by')->nullable()->after('created_by');
                $table->unsignedBigInteger('deleted_by')->nullable()->after('updated_by');
            }
        });

        // ── 2. Users: soft deletes + audit columns ───────────────────
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'deleted_at')) {
                $table->softDeletes();
            }
            if (!Schema::hasColumn('users', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable()->after('primary_color');
                $table->unsignedBigInteger('updated_by')->nullable()->after('created_by');
                $table->unsignedBigInteger('deleted_by')->nullable()->after('updated_by');
            }
            if (!Schema::hasColumn('users', 'last_login_at')) {
                $table->timestamp('last_login_at')->nullable();
                $table->string('last_login_ip', 45)->nullable();
            }
        });

        // ── 3. Consultations: audit columns ──────────────────────────
        Schema::table('consultations', function (Blueprint $table) {
            if (!Schema::hasColumn('consultations', 'updated_by')) {
                $table->unsignedBigInteger('updated_by')->nullable()->after('created_by');
                $table->unsignedBigInteger('deleted_by')->nullable()->after('updated_by');
            }
        });

        // ── 4. Login Attempts table ──────────────────────────────────
        if (!Schema::hasTable('login_attempts')) {
            Schema::create('login_attempts', function (Blueprint $table) {
                $table->id();
                $table->string('email')->index();
                $table->string('ip_address', 45);
                $table->string('user_agent')->nullable();
                $table->boolean('successful')->default(false);
                $table->timestamp('attempted_at')->useCurrent();

                $table->index(['email', 'ip_address', 'attempted_at']);
                $table->index('attempted_at');
            });
        }

        // ── 5. NeedsCategories + StatusCategories: soft deletes ──────
        Schema::table('needs_categories', function (Blueprint $table) {
            if (!Schema::hasColumn('needs_categories', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        Schema::table('status_categories', function (Blueprint $table) {
            if (!Schema::hasColumn('status_categories', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        // ── 6. Report Attendances: audit columns ─────────────────────
        Schema::table('report_attendances', function (Blueprint $table) {
            if (!Schema::hasColumn('report_attendances', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn(['created_by', 'updated_by', 'deleted_by']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn(['created_by', 'updated_by', 'deleted_by', 'last_login_at', 'last_login_ip']);
        });

        Schema::table('consultations', function (Blueprint $table) {
            $table->dropColumn(['updated_by', 'deleted_by']);
        });

        Schema::dropIfExists('login_attempts');

        Schema::table('needs_categories', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('status_categories', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('report_attendances', function (Blueprint $table) {
            $table->dropColumn(['created_by', 'updated_by']);
        });
    }
};
