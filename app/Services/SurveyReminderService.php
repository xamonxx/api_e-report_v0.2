<?php

namespace App\Services;

use App\Events\SurveyReminderDue;
use App\Models\Survey;
use App\Models\SurveyNotification;
use App\Models\SurveyReminderDelivery;
use App\Models\SurveyReminderSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pengingat survey configurable (B3). Satu baris SurveyReminderDelivery per
 * (survey, schedule_revision, penerima) - replan() dipanggil di TRANSAKSI
 * yang sama dengan assign/reschedule/unassign/cancel/start/result supaya
 * versi penugasan dan pengingatnya selalu konsisten (lihat
 * Decision-Log/Change-Log 2026-09-26 "B3").
 */
class SurveyReminderService
{
    /**
     * Dipanggil setelah survey disimpan sebagai `scheduled` (assign atau
     * reschedule-assignment). Menaikkan schedule_revision, membatalkan
     * pending lama, lalu membuat delivery baru kalau setting aktif.
     */
    public function replan(Survey $survey): void
    {
        $survey->schedule_revision = $survey->schedule_revision + 1;
        $survey->save();

        $this->cancelPending($survey);

        if ($survey->state !== Survey::STATE_SCHEDULED || ! $survey->surveyor_id || ! $survey->scheduled_at) {
            return;
        }

        $setting = SurveyReminderSetting::current();
        if (! $setting->enabled) {
            return;
        }

        $dueAt = $this->computeDueAt($survey->scheduled_at, $setting->lead_minutes);
        if ($dueAt === null) {
            // Jadwal sudah lewat - tidak relevan diingatkan "sebelum survey".
            return;
        }

        SurveyReminderDelivery::query()->updateOrCreate(
            [
                'survey_id' => $survey->id,
                'schedule_revision' => $survey->schedule_revision,
                'recipient_id' => $survey->surveyor_id,
            ],
            [
                'scheduled_at_snapshot' => $survey->scheduled_at,
                'lead_minutes' => $setting->lead_minutes,
                'due_at' => $dueAt,
                'status' => SurveyReminderDelivery::STATUS_PENDING,
            ]
        );
    }

    /**
     * Dipanggil saat survey berhenti eligible untuk pengingat "sebelum
     * jadwal" - unassign, cancel, start (sudah berlangsung, tidak perlu
     * diingatkan lagi), submitResult (selesai). Membatalkan SEMUA delivery
     * yang belum final, bukan cuma revision saat ini - survey yang sudah
     * bukan scheduled tidak boleh py pengingat pending versi mana pun.
     */
    public function cancelPending(Survey $survey): void
    {
        SurveyReminderDelivery::query()
            ->where('survey_id', $survey->id)
            ->whereIn('status', [SurveyReminderDelivery::STATUS_PENDING, SurveyReminderDelivery::STATUS_PROCESSING])
            ->update(['status' => SurveyReminderDelivery::STATUS_CANCELLED]);
    }

    /**
     * due_at = scheduled_at - lead_minutes. Kalau itu sudah lewat tapi
     * scheduled_at sendiri belum, due segera (now) - "jadwal mepet" di
     * kontrak produk. Kalau scheduled_at sendiri sudah lewat, null (tidak
     * relevan lagi, bukan tugas replan() membuat delivery expired).
     */
    public function computeDueAt(Carbon $scheduledAt, int $leadMinutes): ?Carbon
    {
        if ($scheduledAt->isPast()) {
            return null;
        }

        $due = $scheduledAt->copy()->subMinutes($leadMinutes);

        return $due->isPast() ? now() : $due;
    }

    /**
     * Dipanggil setelah SurveyReminderSetting disimpan. Pending yang sudah
     * ada dihitung ulang due_at-nya pakai lead_minutes baru (disable
     * langsung membatalkan semuanya, biar tidak ada yang kepancing terkirim
     * pakai setting lama) - "notified" tidak disentuh, versi yang sudah
     * diberi tahu tidak dikirim ulang cuma karena setting berubah.
     */
    public function replanForSettingChange(SurveyReminderSetting $setting): void
    {
        if (! $setting->enabled) {
            SurveyReminderDelivery::query()->pending()->update(['status' => SurveyReminderDelivery::STATUS_CANCELLED]);

            return;
        }

        SurveyReminderDelivery::query()->pending()->with('survey')->chunkById(100, function ($deliveries) use ($setting) {
            foreach ($deliveries as $delivery) {
                $scheduledAt = $delivery->survey?->scheduled_at;
                if (! $scheduledAt) {
                    $delivery->update(['status' => SurveyReminderDelivery::STATUS_EXPIRED]);

                    continue;
                }
                $dueAt = $this->computeDueAt($scheduledAt, $setting->lead_minutes);
                if ($dueAt === null) {
                    $delivery->update(['status' => SurveyReminderDelivery::STATUS_EXPIRED]);

                    continue;
                }
                $delivery->update(['lead_minutes' => $setting->lead_minutes, 'due_at' => $dueAt]);
            }
        });
    }

    /** @return \Illuminate\Support\Collection<int, SurveyReminderDelivery> */
    public function dueDeliveries(int $limit = 100)
    {
        // Reclaim baris yang "processing" tapi macet (worker crash sebelum
        // sempat menyelesaikan) - lease dianggap basi setelah 10 menit.
        SurveyReminderDelivery::query()
            ->where('status', SurveyReminderDelivery::STATUS_PROCESSING)
            ->where('claimed_at', '<=', now()->subMinutes(10))
            ->update(['status' => SurveyReminderDelivery::STATUS_PENDING, 'claimed_at' => null, 'lease_token' => null]);

        return SurveyReminderDelivery::query()->due()->orderBy('due_at')->limit($limit)->get(['id']);
    }

    /**
     * Klaim satu delivery dengan row lock supaya dua runner yang kebetulan
     * tumpang tindih (mis. scheduler telat + retry manual) tidak pernah
     * memproses baris yang sama dua kali - satu-satunya penjaga konsistensi
     * yang sebenarnya, bukan withoutOverlapping() di level scheduler yang
     * cuma mengurangi peluang tabrakan.
     */
    public function claim(int $deliveryId): ?SurveyReminderDelivery
    {
        return DB::transaction(function () use ($deliveryId) {
            $delivery = SurveyReminderDelivery::query()->lockForUpdate()->find($deliveryId);
            if (! $delivery || $delivery->status !== SurveyReminderDelivery::STATUS_PENDING) {
                return null;
            }

            $delivery->update([
                'status' => SurveyReminderDelivery::STATUS_PROCESSING,
                'claimed_at' => now(),
                'lease_token' => (string) Str::uuid(),
                'attempts' => $delivery->attempts + 1,
            ]);

            return $delivery;
        });
    }

    /**
     * Verifikasi ulang kelayakan SETELAH klaim (state/revision/penerima/
     * setting bisa saja berubah sejak due_at dihitung), lalu buat notifikasi
     * in-app + event realtime privat dalam satu transaksi, dan coba push di
     * luar transaksi (fail-open, satu percobaan - lihat pushOnce()).
     */
    public function process(SurveyReminderDelivery $delivery): void
    {
        $delivery->loadMissing('survey', 'recipient');
        $survey = $delivery->survey;
        $recipient = $delivery->recipient;
        $setting = SurveyReminderSetting::current();

        $stillEligible = $survey
            && $survey->state === Survey::STATE_SCHEDULED
            && (int) $survey->schedule_revision === (int) $delivery->schedule_revision
            && (int) $survey->surveyor_id === (int) $delivery->recipient_id
            && $recipient && ! $recipient->trashed() && $recipient->hasSurveyTeam()
            && $setting->enabled;

        if (! $stillEligible) {
            $delivery->update(['status' => SurveyReminderDelivery::STATUS_EXPIRED]);

            return;
        }

        DB::transaction(function () use ($delivery, $survey, $recipient, $setting) {
            $locked = SurveyReminderDelivery::query()->lockForUpdate()->find($delivery->id);
            if (! $locked || $locked->status !== SurveyReminderDelivery::STATUS_PROCESSING || $locked->notification_id) {
                return;
            }

            $message = $this->renderMessage($setting->message_template, $survey);
            $notification = SurveyNotification::create([
                'survey_id' => $survey->id,
                'user_id' => $recipient->id,
                'action' => 'schedule_reminder',
                'title' => 'Pengingat Survey',
                'message' => $message,
            ]);

            $locked->update([
                'status' => SurveyReminderDelivery::STATUS_NOTIFIED,
                'notification_id' => $notification->id,
            ]);

            app(NotificationSummaryService::class)->forgetForUser((int) $recipient->id);

            try {
                SurveyReminderDue::dispatch($survey, $recipient->id, $message);
            } catch (Throwable $e) {
                Log::warning('Survey reminder realtime broadcast gagal; notifikasi in-app tetap tersimpan.', [
                    'delivery_id' => $locked->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        $delivery->refresh();
        if ($delivery->status === SurveyReminderDelivery::STATUS_NOTIFIED) {
            $this->pushOnce($delivery, $recipient->id, $this->renderMessage($setting->message_template, $survey));
        }
    }

    /**
     * Satu percobaan pemanggilan transport per delivery: push_attempted_at
     * di-set atomik SEBELUM kirim, jadi retry (mis. job diulang manual)
     * tidak pernah memanggil transport dua kali - trade-off yang disengaja
     * (ADR: kalau proses mati tepat di antara marker dan kirim, statusnya
     * "unknown", bukan dicoba lagi). In-app tetap jadi bukti utama pengingat
     * dibuat; push cuma percobaan tambahan, bukan jaminan delivery.
     */
    private function pushOnce(SurveyReminderDelivery $delivery, int $recipientId, string $message): void
    {
        $claimed = SurveyReminderDelivery::query()
            ->whereKey($delivery->id)
            ->whereNull('push_attempted_at')
            ->update(['push_attempted_at' => now(), 'push_status' => SurveyReminderDelivery::PUSH_UNKNOWN]);

        if ($claimed === 0) {
            return;
        }

        try {
            app(WebPushService::class)->sendToUsers([$recipientId], [
                'title' => 'Pengingat Survey',
                'body' => $message,
                'url' => '/surveys',
                'tag' => 'survey-reminder-'.$delivery->id,
            ]);
            $delivery->update(['push_status' => SurveyReminderDelivery::PUSH_ATTEMPTED]);
        } catch (Throwable $e) {
            $delivery->update([
                'push_status' => SurveyReminderDelivery::PUSH_FAILED,
                'last_error_code' => Str::limit($e->getMessage(), 55, ''),
            ]);
            Log::warning('Survey reminder push gagal.', ['delivery_id' => $delivery->id, 'error' => $e->getMessage()]);
        }
    }

    private function renderMessage(?string $template, Survey $survey): string
    {
        $survey->loadMissing('consultation:id,client_name,city,district');
        $clientName = trim((string) ($survey->consultation?->client_name ?? '')) ?: 'Konsumen';
        $area = collect([$survey->consultation?->district, $survey->consultation?->city])->filter()->implode(', ');
        $tanggal = $survey->scheduled_at?->locale('id')->translatedFormat('d M Y') ?? '-';
        $jam = $survey->scheduled_at?->format('H:i') ?? '-';

        $default = "Survey konsumen {$clientName}".($area ? " ({$area})" : '')." dijadwalkan {$tanggal} pukul {$jam} WIB. Buka aplikasi untuk detail.";
        if (! $template) {
            return $default;
        }

        return strtr($template, [
            '{nama_konsumen}' => $clientName,
            '{tanggal}' => $tanggal,
            '{jam}' => $jam,
        ]);
    }
}
