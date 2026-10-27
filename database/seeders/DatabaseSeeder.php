<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\NeedsCategory;
use App\Models\StatusCategory;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $seedPassword = env('SEED_DEFAULT_PASSWORD', '1234567890d');
        $hashedPassword = Hash::make($seedPassword);
        $superAdminEmail = env('SEED_SUPER_ADMIN_EMAIL', 'superadmin@ereport.app');
        $superAdminName = env('SEED_SUPER_ADMIN_NAME', 'Super Admin');

        // 0. Bersihkan seluruh data dummy operasional & akun lama
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        $tablesToClean = [
            'survey_notifications',
            'survey_reschedules',
            'survey_activity_logs',
            'survey_status_histories',
            'surveys',
            'consultation_status_histories',
            'consultation_needs_category',
            'consultation_notes',
            'reminders',
            'survey_reminders',
            'survey_reminder_deliveries',
            'consultation_imports',
            'consultation_sequences',
            'report_attendances',
            'audit_logs',
            'bug_reports',
            'login_attempts',
            'consultations',
            'account_user',
            'accounts',
        ];

        foreach ($tablesToClean as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->truncate();
            }
        }

        // Hapus semua user selain Super Admin
        if (Schema::hasTable('users')) {
            User::where('role', '!=', UserRole::SuperAdmin)->forceDelete();
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        // 1. Super Admin
        $superAdmin = User::firstOrNew(['email' => $superAdminEmail]);
        $superAdmin->name = $superAdminName;
        $superAdmin->password = $hashedPassword;
        $superAdmin->role = UserRole::SuperAdmin;
        $superAdmin->account_id = null;
        $superAdmin->save();

        // 2. Groups (PC, INSIP, PRIBADI, MITRA SDK, MITRA BSR)
        $groups = [
            ['code' => 'PC', 'name' => 'PC', 'sort_order' => 10],
            ['code' => 'INSIP', 'name' => 'INSIP', 'sort_order' => 20],
            ['code' => 'PRIBADI', 'name' => 'PRIBADI', 'sort_order' => 30],
            ['code' => 'MITRA SDK', 'name' => 'MITRA SDK', 'sort_order' => 40],
            ['code' => 'MITRA BSR', 'name' => 'MITRA BSR', 'sort_order' => 50],
        ];

        $groupModels = [];
        foreach ($groups as $g) {
            $groupModels[$g['code']] = AccountGroup::firstOrCreate(
                ['code' => $g['code']],
                [
                    'name' => $g['name'],
                    'sort_order' => $g['sort_order'],
                    'is_active' => true,
                ]
            );
        }

        // 3. Admin Users mapping (18 Admins)
        $adminMap = [
            'OMEN'    => 'omen@ereport.app',
            'AGIL'    => 'agil@ereport.app',
            'BILAL'   => 'bilal@ereport.app',
            'YONAS'   => 'yonas@ereport.app',
            'AGUNG'   => 'agung@ereport.app',
            'AZAM'    => 'azam@ereport.app',
            'UNIR'    => 'unir@ereport.app',
            'ANO'     => 'ano@ereport.app',
            'HASNI'   => 'hasni@ereport.app',
            'DENA'    => 'dena@ereport.app',
            'ANNISA'  => 'annisa@ereport.app',
            'DIAN G'  => 'diang@ereport.app',
            'HASAN'   => 'hasan@ereport.app',
            'YANWAR'  => 'yanwar@ereport.app',
            'Sandika' => 'sandika@ereport.app',
            'OCAN'    => 'ocan@ereport.app',
            'SRI'     => 'sri@ereport.app',
            'LISA'    => 'lisa@ereport.app',
        ];

        $adminModels = [];
        foreach ($adminMap as $adminName => $adminEmail) {
            $admin = User::firstOrNew(['email' => $adminEmail]);
            $admin->name = $adminName;
            $admin->password = $hashedPassword;
            $admin->role = UserRole::Admin;
            $admin->save();
            $adminModels[$adminName] = $admin;
        }

        // 4. Accounts list (33 accounts)
        $accounts = [
            ['name' => 'HOME PUTRA INTERIOR',           'admin' => 'OMEN',    'group' => 'PC'],
            ['name' => 'NACIRA INTERIOR',               'admin' => 'OMEN',    'group' => 'PC'],
            ['name' => 'HEYA INTERIOR',                 'admin' => 'AGIL',    'group' => 'PC'],
            ['name' => 'FURNITURE CIMAHI',              'admin' => 'SRI',     'group' => 'PC'],
            ['name' => 'ZODIAK INTERIOR',               'admin' => 'BILAL',   'group' => 'PC'],
            ['name' => 'AKBAR INTERIOR',                'admin' => 'YONAS',   'group' => 'PC'],
            ['name' => 'DEKOR INTERIOR',                'admin' => 'AGUNG',   'group' => 'PC'],
            ['name' => 'INTERHOUSE',                    'admin' => 'AGUNG',   'group' => 'PC'],
            ['name' => 'SAVOY INTERIOR',                'admin' => 'AZAM',    'group' => 'PC'],
            ['name' => 'BROTO INTERIOR',                'admin' => 'UNIR',    'group' => 'PC'],
            ['name' => 'INTERIOR SUKABUMI',             'admin' => 'UNIR',    'group' => 'PC'],
            ['name' => 'RAYSA INTERIOR',                'admin' => 'ANO',     'group' => 'PC'],
            ['name' => 'PORTO INTERIOR',                'admin' => 'HASNI',   'group' => 'PC'],
            ['name' => 'MEWAH INTERIOR',                'admin' => 'LISA',    'group' => 'PC'],
            ['name' => 'KITCHENSET SOLUTION BANDUNG',   'admin' => 'HASNI',   'group' => 'PC'],
            ['name' => 'KURNIA INTERIOR',               'admin' => 'DENA',    'group' => 'PC'],
            ['name' => 'MEDIAN INTERIOR',               'admin' => 'HASNI',   'group' => 'PC'],
            ['name' => 'SOLUTION INTERIOR',             'admin' => 'ANO',     'group' => 'PC'],
            ['name' => 'PUTRA MOULDING',                'admin' => 'ANNISA',  'group' => 'INSIP'],
            ['name' => 'RUMA FURNITURE',                'admin' => 'ANNISA',  'group' => 'INSIP'],
            ['name' => 'FULLHOME ID',                   'admin' => 'DIAN G',  'group' => 'PRIBADI'],
            ['name' => 'NISCALA FURNITURE',             'admin' => 'DIAN G',  'group' => 'PRIBADI'],
            ['name' => 'HOME INTERIOR BANDUNG',         'admin' => 'HASAN',   'group' => 'PRIBADI'],
            ['name' => 'PUTRA INTERIOR',                'admin' => 'OMEN',    'group' => 'PRIBADI'],
            ['name' => 'PARTNER INTERIOR',              'admin' => 'YANWAR',  'group' => 'PRIBADI'],
            ['name' => 'ELVAN INTERIOR',                'admin' => 'YANWAR',  'group' => 'PRIBADI'],
            ['name' => 'ARGO INTERIOR',                 'admin' => 'Sandika', 'group' => 'MITRA SDK'],
            ['name' => 'HOME SAVOY INTERIOR',           'admin' => 'Sandika', 'group' => 'MITRA SDK'],
            ['name' => 'YUREE INTERIOR',                'admin' => 'Sandika', 'group' => 'MITRA SDK'],
            ['name' => 'KEJORA INTERIOR',               'admin' => 'OCAN',    'group' => 'MITRA BSR'],
            ['name' => 'GIBRAN INTERIOR',               'admin' => 'OCAN',    'group' => 'MITRA BSR'],
            ['name' => 'INTERIOR KITA',                 'admin' => 'HASNI',   'group' => 'PC'],
            ['name' => 'ALLFAR INTERIOR',               'admin' => 'HASNI',   'group' => 'PC'],
        ];

        foreach ($accounts as $acc) {
            $groupModel = $groupModels[$acc['group']] ?? null;

            $account = Account::firstOrNew(['name' => $acc['name']]);
            $account->description = 'PUTRA CORPORATION';
            $account->account_group = $acc['group'];
            $account->account_group_id = $groupModel?->id;
            $account->save();

            $admin = $adminModels[$acc['admin']] ?? null;
            if ($admin) {
                // Set default account_id if not yet assigned
                if (! $admin->account_id) {
                    $admin->account_id = $account->id;
                    $admin->save();
                }

                // Attach to pivot table for multi-account access
                $account->members()->syncWithoutDetaching([$admin->id]);
                $admin->accounts()->syncWithoutDetaching([$account->id]);
            }
        }

        // 5. Categories & Statuses
        $categoryRenames = [
            'Almunium' => 'Alumunium',
            'Aluminium' => 'Alumunium',
            'Apartement' => 'Interior Apartemen',
            'Apartment' => 'Interior Apartemen',
            'Box' => 'Box Elektronik',
            'Interior Full' => 'Full Home',
            'Jasa Design' => 'Jasa Desain',
            'Kantor' => 'Interior Kantor',
            'Kitchen Set' => 'Kitchenset',
            'Rak Buku' => 'Rak',
            'Renovasi' => 'Renovasi Interior',
            'Renovasi Rumah' => 'Renovasi Interior',
            'Toko' => 'Interior Toko',
            'Lain-lain' => 'Lain-Lain',
        ];

        foreach ($categoryRenames as $from => $to) {
            if (NeedsCategory::where('name', $from)->exists() && ! NeedsCategory::where('name', $to)->exists()) {
                NeedsCategory::where('name', $from)->update(['name' => $to]);
            }
        }

        foreach (NeedsCategory::displayNames() as $name) {
            $cat = NeedsCategory::withTrashed()->where('name', $name)->first();
            if ($cat) {
                if ($cat->trashed()) {
                    $cat->restore();
                }
            } else {
                NeedsCategory::create(['name' => $name]);
            }
        }

        NeedsCategory::whereNotIn('name', NeedsCategory::displayNames())->delete();

        $statuses = [
            ['name' => 'Hanya Tanya Tanya', 'color' => '#eab308', 'css_class' => 'chip-hanya-tanya', 'sort_order' => 1],
            ['name' => 'Request Survey', 'color' => '#8582ff', 'css_class' => 'chip-request-survey', 'sort_order' => 2],
            ['name' => 'Kendala Anggaran', 'color' => '#9f403d', 'css_class' => 'chip-kendala-anggaran', 'sort_order' => 3],
            ['name' => 'Tidak Ada Respon', 'color' => '#737c7f', 'css_class' => 'chip-tidak-ada-respon', 'sort_order' => 4],
            ['name' => 'Selesai/Deal', 'color' => '#006d4a', 'css_class' => 'chip-selesai-deal', 'sort_order' => 5],
            ['name' => 'Masih konsultasi', 'color' => '#2563eb', 'css_class' => 'chip-masih-konsultasi', 'sort_order' => 6],
        ];

        foreach ($statuses as $status) {
            StatusCategory::firstOrCreate(
                ['name' => $status['name']],
                $status
            );
        }
    }
}
