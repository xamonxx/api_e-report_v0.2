<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DummyOperationalDataSeeder extends Seeder
{
    private const TOTAL_REQUESTED = 70;
    private const TOTAL_SCHEDULED = 90;
    private const TOTAL_IN_PROGRESS = 50;
    private const TOTAL_COMPLETED = 50;
    private const TOTAL_CONSULTATION_ONLY = 100;

    public function run(): void
    {
        $this->clearOperationalData();

        $accounts = DB::table('accounts')->orderBy('id')->get(['id', 'name']);
        $admins = DB::table('users')->where('role', 'admin')->get(['id', 'name', 'account_id'])->groupBy('account_id');
        $surveyors = DB::table('users')->where('role', 'surveyor')->orderBy('id')->get(['id', 'name']);
        $manager = DB::table('users')->where('role', 'manager_surveyor')->first(['id', 'name']);
        $categories = DB::table('needs_categories')
            ->whereNotIn('name', ['Tidak konfirmasi'])
            ->orderBy('id')
            ->get(['id', 'name'])
            ->values();
        $statuses = DB::table('status_categories')->get(['id', 'name'])->keyBy('name');
        $surveyResults = DB::table('survey_statuses')->orderBy('id')->get(['id', 'name'])->values();

        if ($accounts->isEmpty() || $surveyors->isEmpty() || $categories->isEmpty()) {
            throw new RuntimeException('Master akun, surveyor, atau kategori belum siap untuk dummy data.');
        }

        $counts = [
            'consultations' => 0,
            'surveys' => 0,
            'pivot' => 0,
            'histories' => 0,
            'activities' => 0,
            'notifications' => 0,
        ];
        $sequence = [];
        $now = Carbon::now('Asia/Bangkok');
        $base = Carbon::create(2026, 7, 29, 9, 0, 0, 'Asia/Bangkok');
        $statePlan = $this->statePlan();

        DB::transaction(function () use (
            $accounts,
            $admins,
            $surveyors,
            $manager,
            $categories,
            $statuses,
            $surveyResults,
            $base,
            $now,
            $statePlan,
            &$sequence,
            &$counts
        ) {
            foreach ($statePlan as $index => $state) {
                $context = $this->makeConsultation(
                    $index,
                    $state,
                    $accounts,
                    $admins,
                    $categories,
                    $statuses,
                    $base,
                    $sequence
                );

                $counts['consultations']++;
                $counts['pivot'] += $context['pivot_count'];

                if ($state === 'none') {
                    continue;
                }

                $surveyCounts = $this->makeSurvey(
                    $index,
                    $state,
                    $context,
                    $surveyors,
                    $manager,
                    $surveyResults,
                    $base,
                    $now
                );

                foreach ($surveyCounts as $key => $value) {
                    $counts[$key] += $value;
                }
            }

            foreach ($sequence as $key => $lastNumber) {
                [$accountId, $yearMonth] = explode('|', $key);

                DB::table('consultation_sequences')->insert([
                    'account_id' => (int) $accountId,
                    'year_month' => $yearMonth,
                    'last_number' => $lastNumber,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });

        Cache::flush();

        $this->command?->info(sprintf(
            'Dummy data siap: %d konsultasi, %d survey, %d kategori pivot, %d histori, %d aktivitas, %d notifikasi.',
            $counts['consultations'],
            $counts['surveys'],
            $counts['pivot'],
            $counts['histories'],
            $counts['activities'],
            $counts['notifications']
        ));
    }

    private function clearOperationalData(): void
    {
        $tables = [
            'survey_notifications',
            'survey_reschedules',
            'survey_activity_logs',
            'survey_status_histories',
            'surveys',
            'consultation_status_histories',
            'consultation_needs_category',
            'consultation_notes',
            'reminders',
            'consultation_imports',
            'consultation_sequences',
            'report_attendances',
            'audit_logs',
            'bug_reports',
            'login_attempts',
            'consultations',
        ];

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach ($tables as $table) {
                DB::table($table)->truncate();
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    private function statePlan(): array
    {
        return [
            ...array_fill(0, self::TOTAL_REQUESTED, 'requested'),
            ...array_fill(0, self::TOTAL_SCHEDULED, 'scheduled'),
            ...array_fill(0, self::TOTAL_IN_PROGRESS, 'in_progress'),
            ...array_fill(0, self::TOTAL_COMPLETED, 'completed'),
            ...array_fill(0, self::TOTAL_CONSULTATION_ONLY, 'none'),
        ];
    }

    private function makeConsultation(
        int $index,
        string $state,
        $accounts,
        $admins,
        $categories,
        $statuses,
        Carbon $base,
        array &$sequence
    ): array {
        $names = [
            'ibu friska',
            'Bapak Jay',
            'Riska K',
            'Lucia Yelly',
            'Tidak ada nama',
            'Pak Andi Santoso',
            'Ibu Maya Permata',
            'Bapak Rudi Hartono',
            'Dewi Anggraini',
            'Ahmad Fauzan',
            'Siti Nurhaliza',
            'Hendra Wijaya',
            'Nadia Putri',
            'Bambang Saputra',
            'Yuni Kartika',
            'Fajar Pratama',
            'Mira Lestari',
            'Agus Setiawan',
            'Fitri Amalia',
            'Doni Saputra',
        ];
        $districts = ['Blimbing', 'Cicendo', 'Cileunyi', 'Bekasi Timur', 'Serpong Utara', 'Tangerang', 'Gubeng', 'Medan Baru', 'Biringkanaya', 'Pontianak Kota', 'Balikpapan Selatan', 'Samarinda Ulu', 'Banjarmasin Tengah', 'Denpasar Barat', 'Mataram', 'Blang Bintang', 'Mahakam Ulu', 'Cikarang Barat'];
        $cities = ['Kota Bandung', 'Kab. Bandung', 'Kota Bekasi', 'Kota Tangerang', 'Kota Jakarta Selatan', 'Kab. Gresik', 'Kota Surabaya', 'Kota Medan', 'Kota Makassar', 'Kota Pontianak', 'Kota Balikpapan', 'Kota Samarinda', 'Kota Banjarmasin', 'Kota Denpasar', 'Kota Mataram', 'Kab. Aceh Besar', 'Kab. Mahakam Ulu', 'Kab. Bekasi'];
        $provinces = ['Jawa Barat', 'Banten', 'DKI Jakarta', 'Jawa Timur', 'Sumatera Utara', 'Sulawesi Selatan', 'Kalimantan Barat', 'Kalimantan Timur', 'Kalimantan Selatan', 'Bali', 'Nusa Tenggara Barat', 'Aceh'];
        $notes = ['Patokan pagar hitam dekat minimarket', 'Klien hanya bisa sore hari', 'Akses gang sempit, parkir di depan komplek', 'Minta ukur kabinet dan backdrop TV', 'Rumah baru selesai plaster, perlu cek ulang minggu depan', 'Koordinasi dengan pasangan klien sebelum datang', 'Prioritas dapur kering dan kamar anak', 'Ada lift barang, hubungi security dulu'];

        $account = $accounts[$index % $accounts->count()];
        $admin = ($admins->get($account->id) ?? collect())->first() ?: DB::table('users')->where('role', 'super_admin')->first();
        $consultDate = $base->copy()->subDays($index % 21)->addHours($index % 8);
        $yearMonth = $consultDate->format('ym');
        $sequenceKey = $account->id . '|' . $yearMonth;
        $sequence[$sequenceKey] = ($sequence[$sequenceKey] ?? 0) + 1;
        $consultationCode = str_pad((string) $account->id, 2, '0', STR_PAD_LEFT)
            . '.' . $yearMonth . '.'
            . str_pad((string) $sequence[$sequenceKey], 4, '0', STR_PAD_LEFT);

        $categoryCount = 1 + ($index % 5 === 0 ? 5 : ($index % 3));
        $categoryStart = $index % max(1, $categories->count());
        $categoryIds = collect(range(0, $categoryCount - 1))
            ->map(fn ($offset) => $categories[($categoryStart + $offset) % $categories->count()]->id)
            ->unique()
            ->values();
        $categoryNames = $categories->whereIn('id', $categoryIds)->pluck('name')->values();

        $statusName = match ($state) {
            'requested', 'scheduled' => 'Request Survey',
            'in_progress' => 'Sedang Survey',
            'completed' => 'Selesai Survey',
            default => ['Hanya Tanya Tanya', 'Masih konsultasi', 'Kendala Anggaran', 'Tidak Ada Respon', 'Nunggu Bangunan', 'Perbandingan Harga'][$index % 6],
        };

        $clientName = $names[$index % count($names)];
        if ($index % 17 === 0) {
            $clientName = 'Tidak ada nama';
        }
        if ($index % 29 === 0) {
            $clientName = 'Nama Konsumen Sangat Panjang Untuk Tes Layout Responsive Nomor ' . $index;
        }

        $district = $districts[$index % count($districts)];
        $city = $cities[$index % count($cities)];
        $province = $provinces[$index % count($provinces)];
        $address = $index % 11 === 0
            ? 'Alamat sangat panjang blok ' . $index . ', dekat jalan utama, patokan toko bangunan, masuk gang kedua setelah masjid, rumah pagar abu-abu, ' . $district . ', ' . $city . ', ' . $province
            : $district . ', ' . $city . ', ' . $province;
        $createdAt = $consultDate->copy()->subHours(2);

        $consultationId = DB::table('consultations')->insertGetId([
            'consultation_id' => $consultationCode,
            'client_name' => $clientName,
            'phone' => '+628' . str_pad((string) (1200000000 + $index), 10, '0', STR_PAD_LEFT),
            'emergency_phone' => $index % 8 === 0 ? '+628' . str_pad((string) (2200000000 + $index), 10, '0', STR_PAD_LEFT) : null,
            'province' => $province,
            'city' => $city,
            'district' => $district,
            'address' => $address,
            'account_id' => $account->id,
            'needs_category_id' => $categoryIds->first(),
            'product_details' => $index % 6 === 0
                ? 'Detail produk panjang: kabinet atas bawah, finishing HPL, meja granit, lampu LED, soft close, revisi ukuran mengikuti hasil survey.'
                : ($categoryNames->implode(', ') ?: null),
            'status_category_id' => $statuses[$statusName]->id ?? $statuses->first()->id,
            'notes' => $notes[$index % count($notes)],
            'created_by' => $admin->id,
            'consultation_date' => $consultDate->toDateString(),
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        foreach ($categoryIds as $categoryId) {
            DB::table('consultation_needs_category')->insert([
                'consultation_id' => $consultationId,
                'needs_category_id' => $categoryId,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }

        return [
            'id' => $consultationId,
            'code' => $consultationCode,
            'account_id' => $account->id,
            'admin_id' => $admin->id,
            'client_name' => $clientName,
            'category_names' => $categoryNames,
            'created_at' => $createdAt,
            'pivot_count' => $categoryIds->count(),
        ];
    }

    private function makeSurvey(int $index, string $state, array $consultation, $surveyors, $manager, $surveyResults, Carbon $base, Carbon $now): array
    {
        $notes = ['Patokan pagar hitam dekat minimarket', 'Klien hanya bisa sore hari', 'Akses gang sempit, parkir di depan komplek', 'Minta ukur kabinet dan backdrop TV', 'Rumah baru selesai plaster, perlu cek ulang minggu depan', 'Koordinasi dengan pasangan klien sebelum datang', 'Prioritas dapur kering dan kamar anak', 'Ada lift barang, hubungi security dulu'];
        $surveyor = $surveyors[$index % $surveyors->count()];
        $requestedAt = $consultation['created_at']->copy()->addMinutes(20);
        $requestedDate = $base->copy()->addDays(($index % 14) - 4)->toDateString();
        $requestedTime = sprintf('%02d:%02d:00', 8 + ($index % 9), ($index % 2) * 30);
        $scheduledAt = $base->copy()->addDays(($index % 18) - 5)->setTime(8 + ($index % 9), ($index % 2) * 30);
        $assignedAt = in_array($state, ['scheduled', 'in_progress', 'completed'], true) ? $requestedAt->copy()->addHours(2) : null;
        $actualStart = in_array($state, ['in_progress', 'completed'], true) ? $scheduledAt->copy()->addMinutes(15 + ($index % 25)) : null;
        $actualFinish = $state === 'completed' ? $actualStart->copy()->addHours(1 + ($index % 3))->addMinutes($index % 45) : null;
        $result = $state === 'completed' ? $surveyResults[$index % $surveyResults->count()] : null;

        $surveyId = DB::table('surveys')->insertGetId([
            'consultation_id' => $consultation['id'],
            'active_key' => $consultation['id'],
            'account_id' => $consultation['account_id'],
            'state' => $state,
            'requested_by' => $consultation['admin_id'],
            'requested_at' => $requestedAt,
            'requested_date' => $requestedDate,
            'requested_time' => $requestedTime,
            'requested_item' => $consultation['category_names']->implode(', '),
            'surveyor_id' => $state === 'requested' ? null : $surveyor->id,
            'assigned_by' => $state === 'requested' ? null : ($manager->id ?? 1),
            'assigned_at' => $assignedAt,
            'scheduled_at' => $state === 'requested' ? null : $scheduledAt,
            'location_notes' => $notes[($index + 2) % count($notes)],
            'admin_notes' => $notes[($index + 3) % count($notes)],
            'manager_notes' => in_array($state, ['scheduled', 'in_progress', 'completed'], true) ? 'Dummy: prioritas jadwal dan cek ukuran di lokasi.' : null,
            'google_maps_url' => $index % 4 === 0 ? null : 'https://maps.app.goo.gl/dummy' . str_pad((string) $index, 4, '0', STR_PAD_LEFT),
            'result_status_id' => $result?->id,
            'result_notes' => $state === 'completed' ? 'Hasil dummy: ' . $result->name . ' - ukuran dan kebutuhan sudah dicatat.' : null,
            'location_condition' => $state === 'completed' ? 'Area siap ukur, akses cukup.' : null,
            'customer_notes' => $state === 'completed' ? 'Klien minta estimasi cepat.' : null,
            'obstacles' => $state === 'completed' && $index % 5 === 0 ? 'Ada perubahan ukuran ruangan.' : null,
            'recommendations' => $state === 'completed' ? 'Lanjut desain awal dan follow up DP.' : null,
            'additional_notes' => $state === 'completed' ? 'Data dummy untuk pengujian tampilan detail.' : null,
            'completed_at' => $actualFinish,
            'actual_start_at' => $actualStart,
            'actual_finish_at' => $actualFinish,
            'created_at' => $requestedAt,
            'updated_at' => $actualFinish ?: ($actualStart ?: ($assignedAt ?: $requestedAt)),
        ]);

        $transitions = [['from' => null, 'to' => 'requested', 'at' => $requestedAt, 'by' => $consultation['admin_id']]];
        if (in_array($state, ['scheduled', 'in_progress', 'completed'], true)) {
            $transitions[] = ['from' => 'requested', 'to' => 'scheduled', 'at' => $assignedAt, 'by' => $manager->id ?? 1];
        }
        if (in_array($state, ['in_progress', 'completed'], true)) {
            $transitions[] = ['from' => 'scheduled', 'to' => 'in_progress', 'at' => $actualStart, 'by' => $surveyor->id];
        }
        if ($state === 'completed') {
            $transitions[] = ['from' => 'in_progress', 'to' => 'completed', 'at' => $actualFinish, 'by' => $surveyor->id];
        }

        foreach ($transitions as $transition) {
            DB::table('survey_status_histories')->insert([
                'survey_id' => $surveyId,
                'from_state' => $transition['from'],
                'to_state' => $transition['to'],
                'changed_by' => $transition['by'],
                'created_at' => $transition['at'],
                'updated_at' => $transition['at'],
            ]);

            DB::table('survey_activity_logs')->insert([
                'survey_id' => $surveyId,
                'consultation_id' => $consultation['id'],
                'user_id' => $transition['by'],
                'user_role' => $transition['by'] === $surveyor->id ? 'surveyor' : ($transition['by'] === ($manager->id ?? null) ? 'manager_surveyor' : 'admin'),
                'action' => 'status_changed',
                'old_status' => $transition['from'],
                'new_status' => $transition['to'],
                'notes' => 'Dummy transition ' . $transition['to'],
                'created_at' => $transition['at'],
                'updated_at' => $transition['at'],
            ]);
        }

        DB::table('survey_notifications')->insert([
            'user_id' => $state === 'requested' ? ($manager->id ?? 1) : $surveyor->id,
            'survey_id' => $surveyId,
            'action' => 'dummy_seed',
            'title' => 'Dummy Survey',
            'message' => 'Data dummy untuk tes banyak data: ' . $consultation['client_name'],
            'read_at' => $index % 3 === 0 ? $now : null,
            'created_at' => $requestedAt,
            'updated_at' => $requestedAt,
        ]);

        return [
            'surveys' => 1,
            'histories' => count($transitions),
            'activities' => count($transitions),
            'notifications' => 1,
        ];
    }
}
