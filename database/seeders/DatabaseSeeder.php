<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\NeedsCategory;
use App\Models\StatusCategory;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $seedPassword = env('SEED_DEFAULT_PASSWORD');
        $hashedPassword = Hash::make($seedPassword ?: Str::random(32));
        $superAdminEmail = env('SEED_SUPER_ADMIN_EMAIL', 'superadmin@example.test');
        $superAdminName = env('SEED_SUPER_ADMIN_NAME', 'Super Admin');

        User::firstOrCreate(
            ['email' => $superAdminEmail],
            [
                'name' => $superAdminName,
                'password' => $hashedPassword,
                'role' => UserRole::SuperAdmin,
                'account_id' => null,
            ]
        );

        $accounts = [
            ['HOME INTERIOR BANDUNG', 'HASAN', 'hasan@homeinteriorbdg.com'],
            ['INTERHOUSE ID', 'ANDIKA', 'andika@interhouseid.com'],
            ['ZODIAK INTERIOR', 'BILAL', 'bilal@zodiakinterior.com'],
            ['AKBAR INTERIOR', 'YONAS', 'yonas@akbarinterior.com'],
            ['PARTNER INTERIOR', 'YANWAR', 'yanwar@partnerinterior.com'],
            ['ELVAN INTERIOR', 'YANWAR', 'yanwar@elvaninterior.com'],
            ['MEWAH INTERIOR', 'NENG SRI', 'nengsri@mewahinterior.com'],
            ['MEDIAN INTERIOR', 'LISA', 'lisa@medianinterior.com'],
            ['ARGO INTERIOR', 'ADI', 'adi@argointerior.com'],
            ['SAVOY INTERIOR', 'AZAM', 'azam@savoyinterior.com'],
            ['FURNITURE CIMAHI', 'ADI', 'adi@furniturecimahi.com'],
            ['DEKOR INTERIOR', 'ADI', 'adi@dekorinterior.com'],
            ['NISCALA INTERIOR', 'RIVALDI', 'rivaldi@niscalainterior.com'],
            ['INTERIOR CUSTOM', 'DIAN GARUT', 'diangarut@interiorcustom.com'],
            ['INTERIOR BANDUNG', 'FIKRI ACENG', 'fikriaceng@interiorbandung.com'],
            ['INTERIOR MODERN', 'FIKRI ACENG', 'fikriaceng@interiormodern.com'],
            ['BROTO INTERIOR', null, null],
            ['KITCHENSET SOLUTION BANDUNG', 'LISA', 'lisa@kitchensetsolution.com'],
            ['GIBRAN INTERIOR', 'LISA', 'lisa@gibraninterior.com'],
            ['HOME SAVOY INTERIOR', 'NENG SRI', 'nengsri@homesavoy.com'],
            ['LAVENTIA', 'ANO', 'ano@laventia.com'],
            ['PUTRO INTERIOR', null, null],
            ['PUSAT INTERIOR', null, null],
            ['KAMARSET', null, null],
            ['HEYA INTERIOR', 'YASID', 'yasid@heyainterior.com'],
            ['KURNIA INTERIOR', 'YASID', 'yasid@kurniainterior.com'],
            ['KEJORA INTERIOR', null, null],
            ['PORTO INTERIOR', 'AGIL', 'agil@portointerior.com'],
            ['ANEKA INTERIOR', 'RAMDAN', 'ramdan@anekainterior.com'],
            ['RADEA INTERIOR', 'AGIL', 'agil@radeainterior.com'],
            ['ELVAN FURNITURE', 'RAMDAN', 'ramdan@elvanfurniture.com'],
            ['PUTRA MOULDING', 'LISA', 'lisa@putramoulding.com'],
        ];

        foreach ($accounts as [$accountName, $adminName, $adminEmail]) {
            $account = Account::firstOrCreate(
                ['name' => $accountName],
                ['description' => 'PUTRA CORPORATION']
            );

            if (! $adminName || ! $adminEmail) {
                continue;
            }

            User::firstOrCreate(
                ['email' => $adminEmail],
                [
                    'name' => $adminName,
                    'password' => $hashedPassword,
                    'role' => UserRole::Admin,
                    'account_id' => $account->id,
                ]
            );
        }

        $categoryRenames = [
            'Aluminium' => 'Almunium',
            'Apartment' => 'Apartement',
            'Interior Full' => 'Full Home',
            'Kitchen Set' => 'Kitchenset',
            'Rak Buku' => 'Rak',
            'Renovasi' => 'Renovasi Rumah',
        ];

        foreach ($categoryRenames as $from => $to) {
            if (NeedsCategory::where('name', $from)->exists() && ! NeedsCategory::where('name', $to)->exists()) {
                NeedsCategory::where('name', $from)->update(['name' => $to]);
            }
        }

        foreach (NeedsCategory::displayNames() as $name) {
            NeedsCategory::firstOrCreate(['name' => $name]);
        }

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
