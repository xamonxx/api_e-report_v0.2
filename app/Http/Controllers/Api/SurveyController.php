<?php

namespace App\Http\Controllers\Api;

use App\Events\SurveyRealtimeUpdated;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\SurveyorScheduleRecapRequest;
use App\Models\Consultation;
use App\Models\Survey;
use App\Models\SurveyActivityLog;
use App\Models\SurveyReschedule;
use App\Models\User;
use App\Services\Reports\SurveyorScheduleRecapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SurveyController extends Controller
{
    /**
     * Relasi standar yang dimuat untuk response survey.
     */
    private function withRelations(): array
    {
        return [
            'consultation:id,consultation_id,client_name,phone,province,city,district,address,account_id,product_details',
            'consultation.account:id,name',
            'surveyor:id,name',
            'assigner:id,name',
            'requester:id,name',
            'resultStatus:id,name,color,css_class',
        ];
    }

    /**
     * Label "Konsumen X (Kecamatan, Kota)" untuk pesan notifikasi.
     */
    private function surveyClientArea(Survey $survey): string
    {
        $survey->loadMissing('consultation:id,client_name,city,district');
        $client = trim((string) ($survey->consultation?->client_name ?? '')) ?: 'Konsumen';
        $area = collect([$survey->consultation?->district, $survey->consultation?->city])
            ->filter()
            ->implode(', ');

        return $area ? "{$client} ({$area})" : $client;
    }

    /**
     * Format tanggal-jam ringkas berbahasa Indonesia untuk pesan notifikasi.
     */
    private function fmtDt($value): string
    {
        if (! $value) {
            return '-';
        }
        $dt = $value instanceof Carbon ? $value : Carbon::parse($value);

        return $dt->locale('id')->translatedFormat('d M Y H:i');
    }

    /**
     * POST /api/v1/consultations/{consultation}/survey
     * Admin/sales mengajukan survey â†’ state=requested (masuk antrian manager).
     */
    public function store(Request $request, Consultation $consultation): JsonResponse
    {
        // Reuse ConsultationPolicy: admin akun sama / super_admin boleh.
        $this->authorize('update', $consultation);
        $validated = $request->validate([
            'requested_date' => ['nullable', 'date', 'after_or_equal:today'],
            'requested_time' => ['nullable', 'date_format:H:i'],
            'requested_item' => ['nullable', 'string', 'max:1000'],
            'admin_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        // Cegah pengajuan ganda selagi masih ada survey aktif (non-cancelled).
        $existing = $consultation->surveys()
            ->where('state', '!=', Survey::STATE_CANCELLED)
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Lead ini sudah memiliki survey aktif.',
                'data' => $existing->load($this->withRelations()),
            ], 422);
        }

        $survey = new Survey([
            'consultation_id' => $consultation->id,
            'account_id' => $consultation->account_id,
            'state' => Survey::STATE_REQUESTED,
            'requested_by' => auth()->id(),
            'requested_at' => now(),
            'requested_date' => $validated['requested_date'] ?? null,
            'requested_time' => $validated['requested_time'] ?? null,
            'requested_item' => $validated['requested_item'] ?? null,
            'admin_notes' => $validated['admin_notes'] ?? null,
        ]);
        $survey->save();
        $survey->transitionTo(Survey::STATE_REQUESTED); // catat history awal (null â†’ requested)

        $this->flushDashboardCache([(int) $consultation->account_id]);
        $requesterName = auth()->user()?->name ?? 'Admin';
        $requestedAt = ! empty($validated['requested_date'])
            ? Carbon::parse($validated['requested_date'].' '.($validated['requested_time'] ?? '23:59'))->toDateTimeString()
            : null;
        SurveyRealtimeUpdated::dispatch(
            $survey,
            'request_created',
            "{$requesterName} mengajukan survey konsumen {$this->surveyClientArea($survey)}" . ($requestedAt ? '. Jadwal diminta: '.$this->fmtDt($requestedAt).'.' : '.')
        );

        return response()->json([
            'message' => 'Survey berhasil diajukan!',
            'data' => $survey->load($this->withRelations()),
        ], 201);
    }

    /**
     * PATCH /api/v1/surveys/{survey}/reschedule
     * Admin mengubah jadwal yang diajukan (requested_date/time) pada survey
     * yang belum berjalan â†’ simpan riwayat + notifikasi ke Manager Surveyor
     * dengan penanda "reschedule".
     */
    public function reschedule(Request $request, Survey $survey): JsonResponse
    {
        $this->authorize('reschedule', $survey);

        if (! in_array($survey->state, [Survey::STATE_REQUESTED, Survey::STATE_SCHEDULED], true)) {
            return response()->json([
                'message' => 'Jadwal hanya bisa diubah selama survey belum berjalan/selesai.',
            ], 422);
        }

        $validated = $request->validate([
            'requested_date' => ['required', 'date', 'after_or_equal:today'],
            'requested_time' => ['nullable', 'date_format:H:i'],
            'admin_notes' => ['nullable', 'string', 'max:5000'],
        ], [
            'requested_date.required' => 'Tanggal survey wajib diisi.',
        ]);

        $newRequestedAt = ! empty($validated['requested_time'])
            ? Carbon::parse($validated['requested_date'].' '.$validated['requested_time'])
            : Carbon::parse($validated['requested_date'].' 23:59:59');
        if ($newRequestedAt->isPast()) {
            return response()->json(['message' => 'Tanggal dan jam survey tidak boleh berada di waktu yang sudah lewat.'], 422);
        }

        // Jadwal lama untuk arsip riwayat (dari requested_date + requested_time).
        $oldAt = $survey->requested_date
            ? Carbon::parse($survey->requested_date->format('Y-m-d').' '.($survey->requested_time ?: '23:59:59'))
            : null;

        // Tidak ada perubahan â†’ tolak agar tidak spam notifikasi.
        if ($oldAt && $oldAt->equalTo($newRequestedAt)) {
            return response()->json(['message' => 'Jadwal yang dimasukkan sama dengan jadwal saat ini.'], 422);
        }

        DB::transaction(function () use ($survey, $validated, $oldAt, $newRequestedAt) {
            $survey->fill([
                'requested_date' => $validated['requested_date'],
                'requested_time' => $validated['requested_time'],
            ]);
            if (array_key_exists('admin_notes', $validated) && $validated['admin_notes'] !== null) {
                $survey->admin_notes = $validated['admin_notes'];
            }
            $survey->save();

            SurveyReschedule::create([
                'survey_id' => $survey->id,
                'source' => SurveyReschedule::SOURCE_ADMIN,
                'field' => SurveyReschedule::FIELD_REQUESTED,
                'old_at' => $oldAt,
                'new_at' => $newRequestedAt,
                'changed_by' => auth()->id(),
                'changed_by_role' => auth()->user()?->role?->value ?? auth()->user()?->role,
                'notes' => $validated['admin_notes'] ?? null,
            ]);

            SurveyActivityLog::create([
                'survey_id' => $survey->id,
                'consultation_id' => $survey->consultation_id,
                'user_id' => auth()->id(),
                'user_role' => auth()->user()?->role?->value ?? auth()->user()?->role,
                'action' => 'rescheduled',
                'old_status' => $survey->state,
                'new_status' => $survey->state,
                'notes' => 'Admin mengubah jadwal survey (reschedule).',
            ]);
        });

        $this->flushDashboardCache([(int) $survey->account_id]);
        $adminName = auth()->user()?->name ?? 'Admin';
        SurveyRealtimeUpdated::dispatch(
            $survey,
            'rescheduled_by_admin',
            "{$adminName} mengubah jadwal survey konsumen {$this->surveyClientArea($survey)} dari {$this->fmtDt($oldAt)} ke {$this->fmtDt($newRequestedAt)}. Mohon validasi ulang."
        );

        return response()->json([
            'message' => 'Jadwal survey berhasil diubah!',
            'data' => $survey->load($this->withRelations()),
        ]);
    }

    /**
     * GET /api/v1/surveys/recap
     * Rekap jadwal surveyor satu minggu Seninâ€“Minggu.
     */
    public function recap(
        SurveyorScheduleRecapRequest $request,
        SurveyorScheduleRecapService $recapService
    ): JsonResponse {
        $this->authorize('viewRecap', Survey::class);

        return response()->json([
            'data' => $recapService->buildForUser($request->user(), $request->validated()),
        ]);
    }

    /**
     * GET /api/v1/surveys
     * Manager: semua survey (lintas akun). Surveyor: hanya miliknya.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Survey::class);

        $user = auth()->user();
        $query = Survey::query()->with($this->withRelations());

        if ($user->isSurveyor()) {
            $query->where('surveyor_id', $user->id);
        } elseif ($user->isAdmin()) {
            $query->where('account_id', $user->account_id);
        }

        // Filters
        if ($request->filled('state')) {
            $query->where('state', $request->string('state'));
        }
        if ($request->filled('account') && $user->isManagerSurveyor()) {
            $query->where('account_id', (int) $request->account);
        }
        if ($request->filled('surveyor_id') && $user->isManagerSurveyor()) {
            $query->where('surveyor_id', (int) $request->surveyor_id);
        }
        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($query) use ($search) {
                $query
                    ->where('requested_item', 'like', "%{$search}%")
                    ->orWhereHas('surveyor', fn ($surveyor) => $surveyor->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('consultation', function ($consultation) use ($search) {
                        $consultation
                            ->where('client_name', 'like', "%{$search}%")
                            ->orWhere('consultation_id', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%")
                            ->orWhere('address', 'like', "%{$search}%")
                            ->orWhereHas('account', fn ($account) => $account->where('name', 'like', "%{$search}%"));
                    });
            });
        }
        if ($request->filled('start_date')) {
            $query->whereDate('scheduled_at', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->whereDate('scheduled_at', '<=', $request->end_date);
        }

        // Antrian requested diurut terlama dulu; sisanya terbaru dulu.
        if ($request->string('state')->value() === Survey::STATE_REQUESTED) {
            $query->orderBy('requested_at');
        } else {
            $query->latest('updated_at');
        }

        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);
        $paginated = $query->paginate($perPage);

        return response()->json([
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /**
     * GET /api/v1/surveys/{survey}
     */
    public function show(Survey $survey): JsonResponse
    {
        $this->authorize('view', $survey);

        return response()->json([
            'data' => $survey->load(array_merge($this->withRelations(), [
                'histories.changedBy:id,name',
                'reschedules.changedBy:id,name',
                'activityLogs.user:id,name,role',
            ])),
        ]);
    }

    /** Surveyor availability for a selected day (manager/super admin). */
    public function availability(Request $request): JsonResponse
    {
        $this->authorize('viewAvailability', Survey::class);

        $date = $request->validate(['date' => ['required', 'date']])['date'];
        $surveyors = User::query()
            ->where('role', UserRole::Surveyor->value)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
        $busy = Survey::query()
            ->whereDate('scheduled_at', $date)
            ->whereIn('state', [Survey::STATE_SCHEDULED, Survey::STATE_IN_PROGRESS])
            ->get(['surveyor_id', 'scheduled_at']);

        return response()->json([
            'data' => $surveyors->map(fn (User $surveyor) => [
                'id' => $surveyor->id,
                'name' => $surveyor->name,
                'email' => $surveyor->email,
                'schedule_count' => $busy->where('surveyor_id', $surveyor->id)->count(),
                'schedules' => $busy->where('surveyor_id', $surveyor->id)
                    ->pluck('scheduled_at')->values(),
            ]),
        ]);
    }

    /** Chronological activity timeline for an authorized survey. */
    public function history(Survey $survey): JsonResponse
    {
        $this->authorize('view', $survey);

        return response()->json([
            'data' => $survey->activityLogs()->with('user:id,name,role')->get(),
        ]);
    }

    /**
     * PATCH /api/v1/surveys/{survey}/assign
     * Manager menetapkan surveyor + jadwal awal: requested â†’ scheduled.
     */
    public function assign(Request $request, Survey $survey): JsonResponse
    {
        $this->authorize('assign', $survey);

        if ($survey->state !== Survey::STATE_REQUESTED) {
            return response()->json([
                'message' => 'Survey yang sudah dijadwalkan harus di-reschedule, bukan dijadwalkan ulang.',
            ], 422);
        }

        $validated = $request->validate([
            'surveyor_id' => ['required', 'integer', 'exists:users,id'],
            'scheduled_at' => ['required', 'date'],
            'location_notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'surveyor_id.required' => 'Surveyor wajib dipilih.',
            'scheduled_at.required' => 'Tanggal survey wajib diisi.',
        ]);

        $surveyor = User::find($validated['surveyor_id']);
        if (! $surveyor || $surveyor->role !== UserRole::Surveyor) {
            return response()->json([
                'message' => 'User yang dipilih bukan surveyor.',
            ], 422);
        }

        $scheduledAt = Carbon::parse($validated['scheduled_at']);
        if ($scheduledAt->isPast()) {
            return response()->json(['message' => 'Tanggal dan jam survey tidak boleh berada di waktu yang sudah lewat.'], 422);
        }

        DB::transaction(function () use ($survey, $surveyor, $scheduledAt, $validated) {
            $lockedSurvey = Survey::query()->lockForUpdate()->findOrFail($survey->id);
            if ($lockedSurvey->state !== Survey::STATE_REQUESTED) {
                abort(422, 'Survey sudah dijadwalkan oleh pengguna lain.');
            }

            $conflict = Survey::query()
                ->where('surveyor_id', $surveyor->id)
                ->where('state', Survey::STATE_SCHEDULED)
                ->where('scheduled_at', $scheduledAt)
                ->lockForUpdate()
                ->exists();
            if ($conflict) {
                abort(422, 'Surveyor sudah memiliki jadwal pada waktu tersebut.');
            }

            $lockedSurvey->fill([
                'surveyor_id' => $surveyor->id,
                'assigned_by' => auth()->id(),
                'assigned_at' => now(),
                'scheduled_at' => $scheduledAt,
                'location_notes' => $validated['location_notes'] ?? null,
            ]);
            $lockedSurvey->transitionTo(Survey::STATE_SCHEDULED);
        });

        $updatedSurvey = $survey->fresh();
        $this->flushDashboardCache([(int) $updatedSurvey->account_id]);
        SurveyRealtimeUpdated::dispatch(
            $updatedSurvey,
            'scheduled',
            "Anda ditugaskan survey konsumen {$this->surveyClientArea($updatedSurvey)} pada {$this->fmtDt($updatedSurvey->scheduled_at)}."
        );

        return response()->json([
            'message' => 'Surveyor & jadwal berhasil ditetapkan!',
            'data' => $updatedSurvey->load($this->withRelations()),
        ]);
    }

    /**
     * PATCH /api/v1/surveys/{survey}/reschedule-assignment
     * Manager mengubah jadwal final dan/atau surveyor pada survey scheduled.
     */
    public function rescheduleAssignment(Request $request, Survey $survey): JsonResponse
    {
        $this->authorize('rescheduleAssignment', $survey);

        if ($survey->state !== Survey::STATE_SCHEDULED) {
            return response()->json(['message' => 'Hanya survey yang sudah terjadwal yang dapat di-reschedule.'], 422);
        }

        $validated = $request->validate([
            'surveyor_id' => ['required', 'integer', 'exists:users,id'],
            'scheduled_at' => ['required', 'date'],
            'location_notes' => ['nullable', 'string', 'max:2000'],
            'manager_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $surveyor = User::find($validated['surveyor_id']);
        if (! $surveyor || $surveyor->role !== UserRole::Surveyor) {
            return response()->json(['message' => 'User yang dipilih bukan surveyor.'], 422);
        }

        $newAt = Carbon::parse($validated['scheduled_at']);
        if ($newAt->isPast()) {
            return response()->json(['message' => 'Tanggal dan jam survey tidak boleh berada di waktu yang sudah lewat.'], 422);
        }

        $oldAt = $survey->scheduled_at;
        $oldSurveyorId = $survey->surveyor_id;
        if ($oldAt && $oldAt->equalTo($newAt) && (int) $oldSurveyorId === (int) $surveyor->id) {
            return response()->json(['message' => 'Jadwal dan surveyor belum berubah.'], 422);
        }

        DB::transaction(function () use ($survey, $surveyor, $newAt, $validated, $oldAt) {
            $lockedSurvey = Survey::query()->lockForUpdate()->findOrFail($survey->id);
            if ($lockedSurvey->state !== Survey::STATE_SCHEDULED) {
                abort(422, 'Survey tidak lagi berada pada status terjadwal.');
            }

            $conflict = Survey::query()
                ->whereKeyNot($lockedSurvey->id)
                ->where('surveyor_id', $surveyor->id)
                ->where('state', Survey::STATE_SCHEDULED)
                ->where('scheduled_at', $newAt)
                ->lockForUpdate()
                ->exists();
            if ($conflict) {
                abort(422, 'Surveyor sudah memiliki jadwal pada waktu tersebut.');
            }

            $lockedSurvey->fill([
                'surveyor_id' => $surveyor->id,
                'assigned_by' => auth()->id(),
                'assigned_at' => now(),
                'scheduled_at' => $newAt,
                'location_notes' => $validated['location_notes'] ?? null,
            ]);
            if (array_key_exists('manager_notes', $validated) && $validated['manager_notes'] !== null) {
                $lockedSurvey->manager_notes = $validated['manager_notes'];
            }
            $lockedSurvey->save();

            SurveyReschedule::create([
                'survey_id' => $lockedSurvey->id,
                'source' => SurveyReschedule::SOURCE_MANAGER,
                'field' => SurveyReschedule::FIELD_SCHEDULED,
                'old_at' => $oldAt,
                'new_at' => $newAt,
                'changed_by' => auth()->id(),
                'changed_by_role' => auth()->user()?->role?->value ?? auth()->user()?->role,
                'notes' => $validated['manager_notes'] ?? null,
            ]);

            SurveyActivityLog::create([
                'survey_id' => $lockedSurvey->id,
                'consultation_id' => $lockedSurvey->consultation_id,
                'user_id' => auth()->id(),
                'user_role' => auth()->user()?->role?->value ?? auth()->user()?->role,
                'action' => 'rescheduled_by_manager',
                'old_status' => Survey::STATE_SCHEDULED,
                'new_status' => Survey::STATE_SCHEDULED,
                'notes' => 'Manager Surveyor mengubah jadwal penugasan survey.',
            ]);
        });

        $updatedSurvey = $survey->fresh();
        $this->flushDashboardCache([(int) $updatedSurvey->account_id]);
        $managerName = auth()->user()?->name ?? 'Manager Surveyor';
        SurveyRealtimeUpdated::dispatch(
            $updatedSurvey,
            'rescheduled_by_manager',
            "{$managerName} mengubah jadwal survey {$this->surveyClientArea($updatedSurvey)} dari {$this->fmtDt($oldAt)} ke {$this->fmtDt($newAt)}. Surveyor: ".($updatedSurvey->surveyor?->name ?? 'Belum ditentukan').'.'
        );

        return response()->json([
            'message' => 'Jadwal survey berhasil di-reschedule.',
            'data' => $updatedSurvey->load($this->withRelations()),
        ]);
    }

    /** Surveyor memulai kunjungan dan waktu aktual direkam otomatis. */
    public function start(Survey $survey): JsonResponse
    {
        $this->authorize('start', $survey);

        if ($survey->state !== Survey::STATE_SCHEDULED) {
            return response()->json(['message' => 'Hanya survey terjadwal yang dapat dimulai.'], 422);
        }

        $survey->actual_start_at = now();
        $survey->transitionTo(Survey::STATE_IN_PROGRESS);
        $updatedSurvey = $survey->fresh();
        $this->flushDashboardCache([(int) $updatedSurvey->account_id]);
        $surveyorName = auth()->user()?->name ?? ($updatedSurvey->surveyor?->name ?? 'Surveyor');
        SurveyRealtimeUpdated::dispatch(
            $updatedSurvey,
            'started',
            "{$surveyorName} sedang memulai survey konsumen {$this->surveyClientArea($updatedSurvey)}."
        );

        return response()->json([
            'message' => 'Survey dimulai dan waktu aktual telah dicatat.',
            'data' => $updatedSurvey->load($this->withRelations()),
        ]);
    }

    /**
     * PATCH /api/v1/surveys/{survey}/result
     * Surveyor mengisi hasil; waktu selesai dicatat otomatis.
     */
    public function submitResult(Request $request, Survey $survey): JsonResponse
    {
        $this->authorize('submitResult', $survey);

        if (! in_array($survey->state, [Survey::STATE_SCHEDULED, Survey::STATE_IN_PROGRESS], true)) {
            return response()->json([
                'message' => 'Survey belum dijadwalkan atau sudah selesai.',
            ], 422);
        }

        $validated = $request->validate([
            'result_status_id' => ['required', 'integer', 'exists:survey_statuses,id'],
            'result_notes' => ['nullable', 'string', 'max:5000'],
        ], [
            'result_status_id.required' => 'Status hasil survey wajib dipilih.',
        ]);

        $survey->fill([
            'result_status_id' => $validated['result_status_id'],
            'result_notes' => $validated['result_notes'] ?? null,
            'actual_start_at' => $survey->actual_start_at ?? now(),
            'actual_finish_at' => now(),
            'completed_at' => now(),
        ]);
        $survey->transitionTo(Survey::STATE_COMPLETED);

        $updatedSurvey = $survey->fresh()->load($this->withRelations());
        $this->flushDashboardCache([(int) $updatedSurvey->account_id]);
        $surveyorName = auth()->user()?->name ?? ($updatedSurvey->surveyor?->name ?? 'Surveyor');
        SurveyRealtimeUpdated::dispatch(
            $updatedSurvey,
            'completed',
            "{$surveyorName} selesai survey konsumen {$this->surveyClientArea($updatedSurvey)}. Hasil: ".($updatedSurvey->resultStatus?->name ?? 'Belum ditentukan').'.'
        );

        return response()->json([
            'message' => 'Hasil survey berhasil disimpan!',
            'data' => $updatedSurvey,
        ]);
    }

    /**
     * PATCH /api/v1/surveys/{survey}/cancel
     */
    public function cancel(Survey $survey): JsonResponse
    {
        $this->authorize('cancel', $survey);

        if ($survey->state === Survey::STATE_COMPLETED) {
            return response()->json([
                'message' => 'Survey yang sudah selesai tidak dapat dibatalkan.',
            ], 422);
        }

        $survey->transitionTo(Survey::STATE_CANCELLED);
        $this->flushDashboardCache([(int) $survey->account_id]);
        SurveyRealtimeUpdated::dispatch($survey, 'cancelled', 'Survey telah dibatalkan.');

        return response()->json([
            'message' => 'Survey dibatalkan.',
            'data' => $survey->load($this->withRelations()),
        ]);
    }

    private function flushDashboardCache(array $accountIds = []): void
    {
        Cache::forget('dashboard:super_admin:' . auth()->id());

        foreach (collect($accountIds)->filter(fn ($id) => (int) $id > 0)->unique() as $accountId) {
            Cache::forget("dashboard:admin:{$accountId}");
        }
    }
}
