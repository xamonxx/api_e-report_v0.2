<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\Consultation;
use App\Models\Survey;
use App\Models\User;
use App\Support\AccountGroup;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Data demo untuk memverifikasi Rekap Jadwal Surveyor.
 *
 * Distribusinya sengaja timpang â€” itu yang menguji layout dan aturannya:
 *  - satu hari > 10 survey  â†’ grid harus tumbuh melewati minimum 10 baris
 *  - satu hari 0 survey     â†’ kolom kosong harus tetap terender penuh
 *  - satu surveyor 3x sehari â†’ nama harus berulang di kolom yang sama
 *  - Senin & Minggu terisi   â†’ sel kuning & oranye ada isinya
 *  - 1 cancelled ber-scheduled_at + 1 requested tanpa scheduled_at
 *    â†’ dua aturan pengecualian; tanpa ini, pengecualiannya tidak teruji
 *
 * Idempoten: baris demo ditandai lewat location_notes lalu dihapus permanen
 * sebelum diisi ulang, jadi menjalankan berkali-kali tidak menggandakan data.
 *
 * Jalankan: php artisan db:seed --class=Database\\Seeders\\SurveyorRecapDemoSeeder
 */
class SurveyorRecapDemoSeeder extends Seeder
{
    private const MARKER = 'DEMO-RECAP';

    private const SURVEYOR_NAMES = ['ADAM', 'ADI', 'AGIL', 'BAGJA', 'RAFLI', 'DEDE'];

    public function run(): void
    {
        // Seeder ini memalsukan survey. Jangan pernah menyentuh produksi.
        if (app()->environment('production')) {
            $this->command?->error('SurveyorRecapDemoSeeder dilewati: tidak boleh dijalankan di produksi.');

            return;
        }

        $consultations = Consultation::query()
            ->whereNotNull('account_id')
            ->orderBy('id')
            ->get(['id', 'account_id']);

        if ($consultations->isEmpty()) {
            $this->command?->error('Tidak ada konsultasi. Jalankan DatabaseSeeder dulu â€” survey butuh consultation_id.');

            return;
        }

        $this->purgePreviousDemo();

        $surveyors = $this->ensureSurveyors();
        $assigner = User::where('role', UserRole::ManagerSurveyor)->first()
            ?? User::where('role', UserRole::SuperAdmin)->firstOrFail();

        $monday = Carbon::now()->startOfWeek(Carbon::MONDAY);
        $created = $this->seedWeek($monday, $surveyors, $assigner, $consultations);

        $this->command?->info(sprintf(
            'SurveyorRecapDemoSeeder selesai â€” %d survey demo untuk minggu %s s/d %s.',
            $created,
            $monday->format('d/m/Y'),
            $monday->copy()->endOfWeek(Carbon::SUNDAY)->format('d/m/Y')
        ));
    }

    /**
     * Hapus permanen jejak run sebelumnya. forceDelete: baris ini sampah uji,
     * meninggalkannya sebagai soft-delete hanya menumpuk di tabel.
     */
    private function purgePreviousDemo(): void
    {
        $stale = Survey::withTrashed()->where('location_notes', self::MARKER)->get();

        foreach ($stale as $survey) {
            $survey->histories()->delete();
            if (method_exists($survey, 'activityLogs')) {
                $survey->activityLogs()->delete();
            }
            $survey->forceDelete();
        }

        if ($stale->isNotEmpty()) {
            $this->command?->line("  - {$stale->count()} survey demo lama dibersihkan");
        }
    }

    /**
     * Pakai ulang surveyor yang namanya sudah ada, baru buat bila belum.
     *
     * Membuat "ADAM" baru padahal sudah ada ADAM akan menghasilkan dua orang
     * berbeda dengan nama sama: rekapnya benar (dikelompokkan per surveyor_id)
     * tapi tampil sebagai dua baris kembar yang membingungkan saat verifikasi.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function ensureSurveyors()
    {
        $password = Hash::make(env('SEED_DEFAULT_PASSWORD') ?: Str::random(32));

        return collect(self::SURVEYOR_NAMES)->map(function (string $name, int $index) use ($password) {
            $existing = User::where('role', UserRole::Surveyor)
                ->whereRaw('LOWER(name) = ?', [Str::lower($name)])
                ->first();

            if ($existing) {
                $this->command?->line("  = surveyor {$name} (sudah ada, dipakai ulang)");

                return $existing;
            }

            $surveyor = User::create([
                'email' => sprintf('surveyor%d@demo.test', $index + 1),
                'name' => $name,
                'password' => $password,
                'role' => UserRole::Surveyor,
                // Surveyor adalah tim pusat, bukan milik satu akun.
                'account_id' => null,
            ]);

            $this->command?->line("  + surveyor {$name}");

            return $surveyor;
        });
    }

    /**
     * Rencana per hari: tepat 100 survey aktif untuk satu minggu.
     */
    private function seedWeek(Carbon $monday, $surveyors, User $assigner, $consultations): int
    {
        $plan = [0 => 15, 1 => 12, 2 => 16, 3 => 20, 4 => 15, 5 => 12, 6 => 10];
        $states = [Survey::STATE_SCHEDULED, Survey::STATE_IN_PROGRESS, Survey::STATE_COMPLETED];
        $created = 0;
        $cursor = 0;

        foreach ($plan as $dayOffset => $count) {
            for ($slot = 0; $slot < $count; $slot++) {
                $surveyor = $surveyors[$cursor % $surveyors->count()];

                $consultation = $consultations[$cursor % $consultations->count()];

                $this->makeSurvey(
                    $consultation,
                    $surveyor,
                    $assigner,
                    $monday->copy()->addDays($dayOffset)->setTime(8 + intdiv($slot, 2), ($slot % 2) * 30),
                    $states[$cursor % count($states)]
                );

                $created++;
                $cursor++;
            }
        }

        return $created;
    }

    private function makeSurvey(
        $consultation,
        ?User $surveyor,
        ?User $assigner,
        ?Carbon $scheduledAt,
        string $state,
        ?Carbon $requestedOn = null
    ): void {
        $requestedDate = ($scheduledAt ?? $requestedOn ?? Carbon::now())->copy()->startOfDay();

        Survey::create([
            'consultation_id' => $consultation->id,
            'account_id' => $consultation->account_id,
            'state' => $state,
            'requested_by' => $assigner?->id ?? User::query()->value('id'),
            'requested_at' => $requestedDate->copy()->subDay()->setTime(9, 0),
            'requested_date' => $requestedDate,
            'requested_time' => ($scheduledAt ?? $requestedDate)->format('H:i:s'),
            'requested_item' => 'Survey lokasi',
            'surveyor_id' => $surveyor?->id,
            'assigned_by' => $surveyor ? $assigner?->id : null,
            'assigned_at' => $surveyor ? $requestedDate->copy()->setTime(10, 0) : null,
            'scheduled_at' => $scheduledAt,
            // Penanda idempotensi â€” juga dipakai purgePreviousDemo().
            'location_notes' => self::MARKER,
            'cancelled_at' => $state === Survey::STATE_CANCELLED ? $scheduledAt?->copy()->subHour() : null,
            'cancellation_reason' => $state === Survey::STATE_CANCELLED ? 'Dibatalkan klien (data demo)' : null,
            'completed_at' => $state === Survey::STATE_COMPLETED ? $scheduledAt?->copy()->addHours(2) : null,
        ]);
    }
}
