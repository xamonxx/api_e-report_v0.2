<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConsultationRequest;
use App\Models\Account;
use App\Models\Consultation;
use App\Models\ConsultationStatusHistory;
use App\Models\NeedsCategory;
use App\Models\StatusCategory;
use App\Models\Survey;
use App\Services\ConsultationImportService;
use App\Services\NotificationSummaryService;
use App\Services\Reports\LeadsExcelExporter;
use App\Services\Reports\SpreadsheetXmlToXlsxConverter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ConsultationController extends Controller
{
    public function __construct(
        private readonly ConsultationImportService $importService,
        private readonly NotificationSummaryService $notificationSummaryService,
    ) {}

    /**
     * GET /api/v1/consultations
     * Server-side filtered, paginated list.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Consultation::class);

        $user = auth()->user();
        // activeSurvey ikut dimuat supaya UI tahu lead sudah diajukan survey
        // atau belum, tanpa query tambahan per baris.
        $query = Consultation::query()->withProductRelations()->with('activeSurvey');
        $query->forUser($user);

        // Filters
        if ($request->filled('status')) {
            $query->where('status_category_id', $request->status);
        }
        if ($request->boolean('pending_survey')) {
            $query->needsSurveyRequest();
        }
        if ($request->filled('account')) {
            if ($user->isSuperAdmin()) {
                $query->where('account_id', $request->account);
            } elseif ((int) $user->account_id === (int) $request->account) {
                $query->where('account_id', $request->account);
            }
        }
        if ($request->filled('start_date')) {
            $query->whereDate('consultation_date', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->whereDate('consultation_date', '<=', $request->end_date);
        }
        if (! $request->filled('start_date') && ! $request->filled('end_date')) {
            if ($request->filled('month')) {
                $query->whereMonth('consultation_date', (int) $request->month);
                $query->whereYear('consultation_date', (int) $request->input('year', now()->year));
            } elseif ($request->filled('year')) {
                $query->whereYear('consultation_date', (int) $request->year);
            }
        }
        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $normalizedSearch = Consultation::normalizeLeadPhone($search);

            $query->where(function ($q) use ($search, $normalizedSearch) {
                $q->where('client_name', 'like', "%{$search}%")
                  ->orWhere('consultation_id', 'like', "%{$search}%")
                  // Nama akun/cabang ikut dicari. Aman dari kebocoran lintas
                  // akun karena forUser() di atas sudah membatasi admin ke
                  // akunnya sendiri.
                  ->orWhereHas('account', fn ($account) => $account->where('name', 'like', "%{$search}%"));

                if ($normalizedSearch) {
                    $q->orWhereRaw(
                        "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(phone, ''), ' ', ''), '-', ''), '+', ''), '(', ''), ')', '') LIKE ?",
                        ["%{$normalizedSearch}%"]
                    );
                }
            });
        }

        // Sorting. Default memakai tanggal konsultasi, bukan `updated_at`:
        // setelah import massal seluruh baris punya `updated_at` yang nyaris
        // sama, sehingga urutannya mencerminkan waktu import - bukan lead mana
        // yang konsultasinya paling baru. Laporan Excel memakai kolom yang
        // sama dengan arah kebalikannya (terlama dulu).
        $sortBy = $request->input('sort', 'consultation_date');
        $sortDir = $request->input('dir', 'desc');
        $allowedSorts = ['updated_at', 'created_at', 'consultation_date', 'client_name'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortDir === 'asc' ? 'asc' : 'desc');
        }

        // Pemecah seri wajib ada. Import massal memberi ratusan lead
        // `updated_at` yang persis sama, dan tanpa kolom kedua MySQL bebas
        // menentukan urutannya sendiri - antar halaman baris bisa terulang
        // atau justru hilang. `id` menurun sekaligus menjaga lead terbaru
        // tetap di atas, sesuai tampilan halaman Konsultasi.
        $query->orderByDesc('id');

        $perPage = min((int) $request->input('per_page', 25), 100);
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
     * GET /api/v1/consultations/{consultation}
     */
    public function show(Consultation $consultation): JsonResponse
    {
        $this->authorize('view', $consultation);

        $user = auth()->user();
        $consultation->load(array_merge(
            [
                'account',
                'statusCategory',
                // Menentukan apakah kartu Status Survey menampilkan survey yang
                // berjalan atau ajakan "belum diajukan".
                'activeSurvey.surveyor:id,name',
                'activeSurvey.resultStatus:id,name,color',
                'timelineNotes.user',
                'reminders' => function ($query) use ($user) {
                    $query->forUser($user)->with(['user', 'creator']);
                }
            ],
            Consultation::productRelations()
        ));

        // Mark unread notes from others as read
        $updated = $consultation->timelineNotes()
            ->where('user_id', '!=', $user->id)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        if ($updated) {
            $this->notificationSummaryService->forgetForUser($user->id);
        }

        return response()->json([
            'data' => $consultation,
        ]);
    }

    /**
     * POST /api/v1/consultations
     */
    public function store(ConsultationRequest $request): JsonResponse
    {
        $this->authorize('create', Consultation::class);

        $user = auth()->user();
        $validated = $request->validated();
        $productIds = collect($validated['needs_category_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        if ($user->isAdmin()) {
            $validated['account_id'] = $user->account_id;
        }

        // Admin restricted to own account
        if ($user->isAdmin() && $user->account_id != $validated['account_id']) {
            return response()->json([
                'message' => 'Anda tidak memiliki izin untuk membuat data pada akun lain.',
            ], 403);
        }

        $validated['consultation_id'] = Consultation::generateConsultationId($validated['account_id']);
        $validated['created_by'] = $user->id;
        $validated['consultation_date'] = $validated['consultation_date'] ?? now()->toDateString();
        $validated['needs_category_id'] = $productIds->first();

        $consultation = DB::transaction(function () use ($validated, $productIds) {
            $consultation = Consultation::create(Arr::except($validated, ['needs_category_ids']));

            if (Consultation::hasNeedsCategoryPivot()) {
                $consultation->needsCategories()->sync($productIds->all());
            }

            if ($consultation->status_category_id) {
                ConsultationStatusHistory::record($consultation, null, (int) $consultation->status_category_id);
            }

            return $consultation;
        });

        $this->flushDashboardCache([(int) ($validated['account_id'] ?? 0)]);

        return response()->json([
            'data' => $consultation->load(Consultation::productRelations()),
            'message' => 'Konsultasi baru berhasil ditambahkan!',
        ], 201);
    }

    /**
     * PUT /api/v1/consultations/{consultation}
     */
    public function update(ConsultationRequest $request, Consultation $consultation): JsonResponse
    {
        $this->authorize('update', $consultation);

        $user = auth()->user();
        $validated = $request->validated();
        $productIds = collect($validated['needs_category_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        if ($user->isAdmin()) {
            $validated['account_id'] = $user->account_id;
        }

        if ($user->isAdmin() && $user->account_id != $validated['account_id']) {
            return response()->json([
                'message' => 'Anda tidak memiliki izin untuk memindahkan data ke akun lain.',
            ], 403);
        }

        $validated['needs_category_id'] = $productIds->first();
        $previousAccountId = (int) $consultation->account_id;
        $previousStatusId = $consultation->status_category_id;

        DB::transaction(function () use ($consultation, $validated, $productIds, $previousStatusId) {
            $consultation->update(Arr::except($validated, ['needs_category_ids']));

            if (Consultation::hasNeedsCategoryPivot()) {
                $consultation->needsCategories()->sync($productIds->all());
            }

            ConsultationStatusHistory::record($consultation, $previousStatusId, (int) $consultation->status_category_id);
        });

        $this->flushDashboardCache([
            $previousAccountId,
            (int) ($validated['account_id'] ?? $previousAccountId),
        ]);

        return response()->json([
            'data' => $consultation->fresh()->load(Consultation::productRelations()),
            'message' => 'Data konsultasi berhasil diperbarui!',
        ]);
    }

    /**
     * DELETE /api/v1/consultations/{consultation}
     */
    public function destroy(Consultation $consultation): JsonResponse
    {
        $this->authorize('delete', $consultation);

        $affectedAccountId = (int) $consultation->account_id;

        DB::transaction(fn () => $consultation->delete());

        $this->flushDashboardCache([$affectedAccountId]);

        return response()->json([
            'message' => 'Data konsultasi berhasil dihapus!',
        ]);
    }

    /**
     * GET /api/v1/consultations/id-preview?account_id=...
     */
    public function previewId(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'account_id' => ['nullable', 'integer', 'exists:accounts,id'],
        ]);

        $accountId = $user->isAdmin()
            ? $user->account_id
            : ($validated['account_id'] ?? $user->account_id ?? 1);
        $previewId = Consultation::generateConsultationId($accountId);

        return response()->json([
            'id' => $previewId,
            'consultation_id' => $previewId,
        ]);
    }

    /**
     * GET /api/v1/consultations/import/template
     */
    public function downloadTemplate(LeadsExcelExporter $excelExporter, SpreadsheetXmlToXlsxConverter $xlsxConverter): Response
    {
        $fileName = 'template_import_leads.xlsx';

        return response($xlsxConverter->convert($excelExporter->buildTemplateWorkbook(auth()->user())), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    /**
     * PATCH /api/v1/consultations/{consultation}/status
     */
    public function updateStatus(Request $request, Consultation $consultation): JsonResponse
    {
        $this->authorize('update', $consultation);

        $validated = $request->validate([
            'status_category_id' => ['required', 'integer', 'exists:status_categories,id'],
        ]);

        if ($conflict = $this->surveyStatusConflict($consultation, (int) $validated['status_category_id'])) {
            return response()->json(['message' => $conflict], 422);
        }

        $previousStatusId = $consultation->status_category_id;
        $consultation->update(['status_category_id' => $validated['status_category_id']]);

        ConsultationStatusHistory::record($consultation, $previousStatusId, (int) $consultation->status_category_id);

        $this->flushDashboardCache([(int) $consultation->account_id]);

        return response()->json([
            'message' => 'Status lead berhasil diperbarui!',
            'data' => [
                'old_status_id' => $previousStatusId,
                'new_status_id' => $consultation->status_category_id,
            ],
        ]);
    }

    /**
     * POST /api/v1/consultations/import
     */
    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt|max:5120',
        ]);

        $import = $this->importService->queue($request->file('csv_file'), auth()->user());

        return response()->json([
            'data' => [
                'id' => $import->id,
                'status' => $import->status,
            ],
            'message' => 'Import sedang diproses di background.',
        ], 202);
    }

    /**
     * Cegah status pipeline lead bertabrakan dengan survey yang sedang berjalan.
     *
     * Status "Sedang Survey" dan "Selesai Survey" digerakkan otomatis oleh
     * Survey::transitionTo(). Menggesernya manual membuat lead mengaku selesai
     * survei padahal surveynya belum dijalankan, dan sebaliknya.
     *
     * @return string|null pesan penolakan, atau null bila boleh
     */
    private function surveyStatusConflict(Consultation $consultation, int $targetStatusId): ?string
    {
        $survey = $consultation->surveys()
            ->where('state', '!=', Survey::STATE_CANCELLED)
            ->latest('id')
            ->first();

        if (! $survey) {
            return null; // Tidak ada survey aktif: status bebas digeser.
        }

        $target = StatusCategory::find($targetStatusId);
        $targetName = mb_strtolower(trim((string) $target?->name));

        $stageOf = fn (string $name) => match ($name) {
            'sedang survey' => Survey::STATE_IN_PROGRESS,
            'selesai survey' => Survey::STATE_COMPLETED,
            default => null,
        };

        $requiredState = $stageOf($targetName);

        if ($requiredState === Survey::STATE_IN_PROGRESS
            && ! in_array($survey->state, [Survey::STATE_IN_PROGRESS, Survey::STATE_COMPLETED], true)) {
            return 'Status "Sedang Survey" mengikuti pelaksanaan survey. Minta surveyor memulai surveynya lebih dulu.';
        }

        if ($requiredState === Survey::STATE_COMPLETED && $survey->state !== Survey::STATE_COMPLETED) {
            return 'Status "Selesai Survey" mengikuti hasil survey. Lengkapi hasil surveynya lebih dulu.';
        }

        // Menggeser keluar dari tahap pengajuan sementara survey masih menunggu
        // akan meninggalkan survey yatim di antrian manager.
        $surveyStageName = mb_strtolower(trim(config('statuses.survey', 'Request Survey')));
        $currentName = mb_strtolower(trim((string) $consultation->statusCategory?->name));

        if ($currentName === $surveyStageName
            && $targetName !== $surveyStageName
            && in_array($survey->state, [Survey::STATE_REQUESTED, Survey::STATE_SCHEDULED], true)
            && $requiredState === null) {
            return 'Lead ini masih punya pengajuan survey yang berjalan. Batalkan surveynya lebih dulu bila ingin memindahkan status.';
        }

        return null;
    }

    private function flushDashboardCache(array $accountIds = []): void
    {
        Cache::forget('dashboard:super_admin:' . auth()->id());

        foreach (collect($accountIds)->filter(fn ($id) => (int) $id > 0)->unique() as $accountId) {
            Cache::forget("dashboard:admin:{$accountId}");
        }
    }
}
