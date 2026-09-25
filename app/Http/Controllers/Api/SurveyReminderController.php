<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Survey;
use App\Models\SurveyReminderDelivery;
use App\Models\SurveyReminderSetting;
use App\Services\SurveyReminderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pengaturan pengingat survey (B3) - super_admin only (dijaga middleware
 * role:super_admin di routes/api.php, bukan cek manual di sini, sama seperti
 * resource master-data lain).
 */
class SurveyReminderController extends Controller
{
    public function __construct(
        private readonly SurveyReminderService $reminderService,
    ) {
    }

    public function showSetting(): JsonResponse
    {
        $setting = SurveyReminderSetting::current();

        return response()->json(['data' => $this->settingPayload($setting)]);
    }

    public function updateSetting(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'lead_minutes' => ['required', 'integer', 'min:1', 'max:10080'],
            'message_template' => ['nullable', 'string', 'max:2000'],
        ], [
            'lead_minutes.min' => 'Pengingat minimal 1 menit sebelum jadwal.',
            'lead_minutes.max' => 'Pengingat maksimal 7 hari (10080 menit) sebelum jadwal.',
        ]);

        $setting = SurveyReminderSetting::current();
        $setting->fill($validated);
        $setting->updated_by = $request->user()->id;
        $setting->save();
        $this->reminderService->replanForSettingChange($setting);

        return response()->json([
            'message' => 'Pengaturan pengingat survey berhasil disimpan.',
            'data' => $this->settingPayload($setting),
        ]);
    }

    /**
     * Preview dampak sebelum aktivasi/ubah setting - jumlah & waktu pengingat
     * yang AKAN terbentuk kalau nilai ini disimpan. TIDAK menulis DB atau
     * mengirim push (kontrak produk: "Preview waktu/jumlah dampak, tanpa DB
     * mutation atau push").
     */
    public function previewSetting(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'lead_minutes' => ['required', 'integer', 'min:1', 'max:10080'],
        ]);

        if (! $validated['enabled']) {
            return response()->json(['data' => ['affected_count' => 0, 'sample' => []]]);
        }

        $eligible = Survey::query()
            ->where('state', Survey::STATE_SCHEDULED)
            ->whereNotNull('surveyor_id')
            ->whereNotNull('scheduled_at')
            ->with(['consultation:id,client_name', 'surveyor:id,name'])
            ->orderBy('scheduled_at')
            ->get();

        $sample = [];
        $affected = 0;
        foreach ($eligible as $survey) {
            $dueAt = $this->reminderService->computeDueAt($survey->scheduled_at, $validated['lead_minutes']);
            if ($dueAt === null) {
                continue;
            }
            $affected++;
            if (count($sample) < 20) {
                $sample[] = [
                    'survey_id' => $survey->id,
                    'client_name' => $survey->consultation?->client_name ?? 'Konsumen',
                    'surveyor_name' => $survey->surveyor?->name,
                    'scheduled_at' => $survey->scheduled_at?->toIso8601String(),
                    'due_at' => $dueAt->toIso8601String(),
                ];
            }
        }

        return response()->json(['data' => ['affected_count' => $affected, 'sample' => $sample]]);
    }

    /** GET /api/v1/survey-reminder-deliveries - riwayat paginated, filter status/periode. */
    public function history(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:pending,processing,notified,cancelled,expired,failed'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = SurveyReminderDelivery::query()
            ->with([
                'survey:id,consultation_id,account_id',
                'survey.consultation:id,client_name',
                'recipient:id,name',
            ])
            ->latest('due_at');

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (! empty($validated['start_date'])) {
            $query->whereDate('due_at', '>=', $validated['start_date']);
        }
        if (! empty($validated['end_date'])) {
            $query->whereDate('due_at', '<=', $validated['end_date']);
        }

        $paginated = $query->paginate($validated['per_page'] ?? 20);

        return response()->json([
            'data' => collect($paginated->items())->map(fn (SurveyReminderDelivery $delivery) => [
                'id' => $delivery->id,
                'survey_id' => $delivery->survey_id,
                'client_name' => $delivery->survey?->consultation?->client_name ?? 'Konsumen',
                'recipient_name' => $delivery->recipient?->name,
                'due_at' => $delivery->due_at?->toIso8601String(),
                'status' => $delivery->status,
                'push_status' => $delivery->push_status,
                'attempts' => $delivery->attempts,
            ]),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    private function settingPayload(SurveyReminderSetting $setting): array
    {
        return [
            'enabled' => $setting->enabled,
            'lead_minutes' => $setting->lead_minutes,
            'message_template' => $setting->message_template,
            'updated_by' => $setting->updatedBy?->name,
            'updated_at' => $setting->updated_at?->toIso8601String(),
        ];
    }
}
