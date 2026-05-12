<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConsultationRequest;
use App\Models\Account;
use App\Models\Consultation;
use App\Models\NeedsCategory;
use App\Models\StatusCategory;
use App\Services\ConsultationImportService;
use App\Services\NotificationSummaryService;
use App\Services\Reports\LeadsExcelExporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Arr;

class ConsultationController extends Controller
{
    public function __construct(
        private readonly ConsultationImportService $consultationImportService,
        private readonly NotificationSummaryService $notificationSummaryService,
    ) {
    }

    public function index(Request $request)
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
            } elseif ($user->account_id == $request->account) {
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
            
            $query->where(function($q) use ($search, $normalizedSearch) {
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

        $consultations = $query
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();

        $statuses = StatusCategory::orderBy('sort_order')->get();
        $accounts = $user->isSuperAdmin() ? Account::orderBy('name')->get() : ($user->account ? collect([$user->account]) : collect([]));

        // Data needed for Create Consultation Modal
        $previewAccountId = $user->isAdmin() ? $user->account_id : ($accounts->first()?->id ?? 1);
        $newId = Consultation::generateConsultationId($previewAccountId);
        $categories = NeedsCategory::forConsultationOptions()->get();
        $provinces = config('wilayah.provinces');

        return view('consultations.index', compact('consultations', 'statuses', 'accounts', 'newId', 'categories', 'provinces'));
    }

    public function create()
    {
        $user = auth()->user();
        $accounts = $user->isSuperAdmin() ? Account::orderBy('name')->get() : ($user->account ? collect([$user->account]) : collect([]));
        
        // Dapatkan default account ID untuk preview ID Consultation
        $previewAccountId = $user->isAdmin() ? $user->account_id : ($accounts->first()?->id ?? 1);
        
        $newId = Consultation::generateConsultationId($previewAccountId);
        $categories = NeedsCategory::forConsultationOptions()->get();
        $statuses = StatusCategory::orderBy('sort_order')->get();

        // Provinsi dari config — satu sumber data (Fix #1a)
        $provinces = config('wilayah.provinces');

        return view('consultations.create', compact('newId', 'categories', 'statuses', 'accounts', 'provinces'));
    }

    /**
     * Store new consultation (lead).
     *
     * Security improvements:
     * - DB::transaction for atomicity (prevents partial writes)
     * - Authorization: admin can only create for their own account
     * - Idempotency: deduplication check via findDuplicateLead()
     * - Input whitelist: only validated fields are accepted
     * - XSS: regex validation on all text fields in ConsultationRequest
     * - audit: created_by automatically set
     */
    public function store(ConsultationRequest $request)
    {
        $user = auth()->user();
        $validated = $request->validated();
        $productIds = collect($validated['needs_category_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        // ── Authorization: admin restricted to own account ───────
        if ($user->isAdmin()) {
            if ($user->account_id != $validated['account_id']) {
                abort(403, 'Anda tidak memiliki izin untuk membuat data pada akun lain.');
            }
        }

        // ── Deduplication Check (idempotency protection) ─────────
        if ($duplicate = Consultation::findDuplicateLead($validated)) {
            return back()
                ->withErrors(['client_name' => 'Lead dengan data yang sama sudah terdaftar pada akun ini.'])
                ->withInput();
        }

        $validated['consultation_id'] = Consultation::generateConsultationId($validated['account_id']);
        $validated['created_by'] = $user->id;
        $validated['consultation_date'] = $validated['consultation_date'] ?? now()->toDateString();
        $validated['needs_category_id'] = $productIds->first();

        DB::transaction(function () use ($validated, $productIds) {
            $consultation = Consultation::create(Arr::except($validated, ['needs_category_ids']));

            if (Consultation::hasNeedsCategoryPivot()) {
                $consultation->needsCategories()->sync($productIds->all());
            }
        });

        $this->flushDashboardCache([(int) ($validated['account_id'] ?? 0)]);

        return redirect()->route('consultations.index')
            ->with('success', 'Konsultasi baru berhasil ditambahkan!');
    }

    public function show(Consultation $consultation)
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

        return view('consultations.show', compact('consultation'));
    }

    public function edit(Consultation $consultation)
    {
        $this->authorize('update', $consultation);
        if (Consultation::hasNeedsCategoryPivot()) {
            $consultation->loadMissing(['needsCategories']);
        }

        $user = auth()->user();
        $categories = NeedsCategory::forConsultationOptions()->get();
        $statuses = StatusCategory::orderBy('sort_order')->get();
        $accounts = $user->isSuperAdmin() ? Account::orderBy('name')->get() : ($user->account ? collect([$user->account]) : collect([]));

        // Provinsi dari config — satu sumber data (Fix #1a)
        $provinces = config('wilayah.provinces');

        return view('consultations.edit', compact('consultation', 'categories', 'statuses', 'accounts', 'provinces'));
    }

    /**
     * Update existing consultation.
     *
     * Security improvements:
     * - Authorization policy check
     * - Admin restricted to own account
     * - Deduplication check (ignoring current record ID)
     * - DB::transaction for atomicity
     * - Only validated fields are updated (prevents NULL overwrite)
     */
    public function update(ConsultationRequest $request, Consultation $consultation)
    {
        $this->authorize('update', $consultation);

        $user = auth()->user();
        $validated = $request->validated();
        $productIds = collect($validated['needs_category_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        // ── Authorization: admin restricted to own account ───────
        if ($user->isAdmin()) {
            if ($user->account_id != $validated['account_id']) {
                abort(403, 'Anda tidak memiliki izin untuk memindahkan data ke akun lain.');
            }
        }

        // ── Deduplication: ignore current record ─────────────────
        if ($duplicate = Consultation::findDuplicateLead($validated, $consultation->id)) {
            return back()
                ->withErrors(['client_name' => 'Lead dengan data yang sama sudah terdaftar pada akun ini.'])
                ->withInput();
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

        return redirect()->route('consultations.index')
            ->with('success', 'Data konsultasi berhasil diperbarui!');
    }

    public function importCsv(Request $request)
    {
        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt|max:5120',
        ]);

        $user = auth()->user();
        $import = $this->consultationImportService->queue($request->file('csv_file'), $user);

        if ($import?->status === 'failed') {
            return back()->with(
                'error',
                'Import CSV gagal: ' . str($import->error_preview)->limit(180)
            );
        }

        return back()->with(
            'success',
            "Import selesai. Data baru: {$import->success_count}, Update: {$import->updated_count}, Error: {$import->error_count}."
        );
    }

    public function downloadTemplate(LeadsExcelExporter $excelExporter)
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
     * Delete consultation.
     *
     * Security:
     * - Authorization policy check
     * - Soft delete (via SoftDeletes trait on model)
     * - Cache invalidation
     */
    public function destroy(Consultation $consultation)
    {
        $this->authorize('delete', $consultation);

        $affectedAccountId = (int) $consultation->account_id;

        DB::transaction(function () use ($consultation) {
            $consultation->delete(); // Soft delete via SoftDeletes trait
        });

        $this->flushDashboardCache([$affectedAccountId]);

        return redirect()->route('consultations.index')
            ->with('success', 'Data konsultasi berhasil dihapus!');
    }

    /**
     * API: Preview consultation ID based on selected account.
     */
    public function previewId(Request $request)
    {
        $user = $request->user();
        $validated = $request->validate([
            'account_id' => ['nullable', 'integer', 'exists:accounts,id'],
        ]);

        $accountId = $user->isAdmin()
            ? $user->account_id
            : ($validated['account_id'] ?? $user->account_id ?? 1);

        $previewId = Consultation::generateConsultationId($accountId);

        return response()->json(['id' => $previewId]);
    }

    private function flushDashboardCache(array $accountIds = []): void
    {
        Cache::forget('dashboard:super_admin');

        foreach (collect($accountIds)->filter(fn ($id) => (int) $id > 0)->unique() as $accountId) {
            Cache::forget("dashboard:admin:{$accountId}");
        }
    }
}
