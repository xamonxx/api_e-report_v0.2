<?php

namespace App\Console\Commands;

use App\Models\Survey;
use App\Models\SurveyReminderDelivery;
use App\Models\SurveyReminderSetting;
use App\Services\SurveyReminderService;
use Illuminate\Console\Command;

/**
 * Cari survey `scheduled` yang eligible tapi belum punya delivery aktif
 * untuk schedule_revision saat ini - menutup celah kalau replan() pernah
 * gagal dipanggil (bug lama, import historis sebelum fitur ini ada, dsb).
 * Mode bawaan cuma pratinjau; --apply baru menulis. Dibatasi ke survey MASA
 * DEPAN saja (kontrak produk: "Jangan memasukkan survey lampau").
 */
class ReconcileSurveyReminders extends Command
{
    protected $signature = 'surveys:reconcile-reminders
        {--apply : Buat delivery yang hilang (tanpa opsi ini hanya menampilkan rencana)}
        {--limit=200 : Jumlah maksimum survey diperiksa per jalan}';

    protected $description = 'Cari survey terjadwal yang eligible pengingat tapi belum punya delivery aktif, lalu buatkan (preview by default).';

    public function handle(SurveyReminderService $reminderService): int
    {
        $apply = (bool) $this->option('apply');
        $limit = max(1, (int) $this->option('limit'));

        $setting = SurveyReminderSetting::current();
        if (! $setting->enabled) {
            $this->warn('Pengingat survey sedang nonaktif (survey_reminder_settings.enabled=false) - tidak ada yang diproses.');

            return self::SUCCESS;
        }

        $activeStatuses = [
            SurveyReminderDelivery::STATUS_PENDING,
            SurveyReminderDelivery::STATUS_PROCESSING,
            SurveyReminderDelivery::STATUS_NOTIFIED,
        ];

        $candidates = Survey::query()
            ->where('state', Survey::STATE_SCHEDULED)
            ->whereNotNull('surveyor_id')
            ->where('scheduled_at', '>', now())
            ->whereDoesntHave('reminderDeliveries', function ($query) use ($activeStatuses) {
                $query->whereColumn('survey_reminder_deliveries.schedule_revision', 'surveys.schedule_revision')
                    ->whereIn('status', $activeStatuses);
            })
            ->limit($limit)
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('Tidak ada survey yang perlu direkonsiliasi - semua sudah punya delivery aktif.');

            return self::SUCCESS;
        }

        $this->info(($apply ? 'MEMBUAT' : 'PRATINJAU (tambahkan --apply untuk menulis)').": {$candidates->count()} survey butuh delivery pengingat.");
        $this->table(
            ['Survey ID', 'Jadwal', 'Surveyor ID'],
            $candidates->map(fn (Survey $s) => [$s->id, $s->scheduled_at?->toDateTimeString(), $s->surveyor_id])
        );

        if (! $apply) {
            $this->warn('Belum ada yang ditulis. Jalankan ulang dengan --apply bila daftar di atas sudah sesuai.');

            return self::SUCCESS;
        }

        $created = 0;
        foreach ($candidates as $survey) {
            $before = SurveyReminderDelivery::query()
                ->where('survey_id', $survey->id)
                ->where('schedule_revision', $survey->schedule_revision)
                ->count();
            $reminderService->replan($survey->fresh());
            $after = SurveyReminderDelivery::query()
                ->where('survey_id', $survey->id)
                ->where('schedule_revision', $survey->fresh()->schedule_revision)
                ->count();
            if ($after > $before) {
                $created++;
            }
        }

        $this->info("Delivery baru dibuat untuk {$created} survey.");

        return self::SUCCESS;
    }
}
