<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConsultationRequest;
use App\Models\Account;
use App\Models\Consultation;
use App\Models\NeedsCategory;
use App\Models\StatusCategory;
use App\Services\ConsultationImportService;
use App\Services\NotificationSummaryService;
use App\Services\Reports\LeadsExcelExporter;
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
        $user = auth()->user();
        $query = Consultation::query()->withProductRelations();
        $query->forUser($user);

        // Filters
        if ($request->filled('status')) {
            $query->where('status_category_id', $request->status);
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
                  ->orWhere('consultation_id', 'like', "%{$search}%");

                if ($normalizedSearch) {
                    $q->orWhereRaw(
                        "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(phone, ''), ' ', ''), '-', ''), '+', ''), '(', ''), ')', '') LIKE ?",
                        ["%{$normalizedSearch}%"]
                    );
                }
            });
        }

        // Sorting
        $sortBy = $request->input('sort', 'updated_at');
        $sortDir = $request->input('dir', 'desc');
        $allowedSorts = ['updated_at', 'created_at', 'consultation_date', 'client_name'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortDir === 'asc' ? 'asc' : 'desc');
        }

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
            ['account', 'statusCategory', 'timelineNotes.user', 'reminders.user'],
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

        // Deduplication check
        if (Consultation::findDuplicateLead($validated)) {
            return response()->json([
                'message' => 'Lead dengan data yang sama sudah terdaftar pada akun ini.',
                'errors' => ['client_name' => ['Lead dengan data yang sama sudah terdaftar.']],
            ], 422);
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

        if ($duplicate = Consultation::findDuplicateLead($validated, $consultation->id)) {
            return response()->json([
                'message' => 'Lead dengan data yang sama sudah terdaftar.',
                'errors' => ['client_name' => ['Lead duplikat ditemukan.']],
            ], 422);
        }

        $validated['needs_category_id'] = $productIds->first();
        $previousAccountId = (int) $consultation->account_id;

        DB::transaction(function () use ($consultation, $validated, $productIds) {
            $consultation->update(Arr::except($validated, ['needs_category_ids']));

            if (Consultation::hasNeedsCategoryPivot()) {
                $consultation->needsCategories()->sync($productIds->all());
            }
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
    public function downloadTemplate(LeadsExcelExporter $excelExporter): Response
    {
        $fileName = 'template_import_leads.xls';

        return response($excelExporter->buildTemplateWorkbook(auth()->user()), 200, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
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

        $previousStatusId = $consultation->status_category_id;
        $consultation->update(['status_category_id' => $validated['status_category_id']]);

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

    private function flushDashboardCache(array $accountIds = []): void
    {
        Cache::forget('dashboard:super_admin:' . auth()->id());

        foreach (collect($accountIds)->filter(fn ($id) => (int) $id > 0)->unique() as $accountId) {
            Cache::forget("dashboard:admin:{$accountId}");
        }
    }
}
