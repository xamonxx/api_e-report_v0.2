<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConsultationRequest;
use App\Models\Account;
use App\Models\Consultation;
use App\Models\ConsultationImport;
use App\Models\NeedsCategory;
use App\Models\StatusCategory;
use App\Services\ConsultationImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Arr;

class ConsultationController extends Controller
{
    public function __construct(
        private readonly ConsultationImportService $consultationImportService
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
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('client_name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('consultation_id', 'like', "%{$search}%");
            });
        }

        $consultations = $query->latest()->paginate(15)->withQueryString();

        $statuses = StatusCategory::orderBy('sort_order')->get();
        $accounts = $user->isSuperAdmin() ? Account::orderBy('name')->get() : ($user->account ? collect([$user->account]) : collect([]));
        $recentImports = ConsultationImport::query()
            ->where('user_id', $user->id)
            ->latest()
            ->take(5)
            ->get();

        // Data needed for Create Consultation Modal
        $previewAccountId = $user->isAdmin() ? $user->account_id : ($accounts->first()?->id ?? 1);
        $newId = Consultation::generateConsultationId($previewAccountId);
        $categories = NeedsCategory::forConsultationOptions()->get();
        $provinces = config('wilayah.provinces');

        return view('consultations.index', compact('consultations', 'statuses', 'accounts', 'newId', 'categories', 'provinces', 'recentImports'));
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
            Cache::forget("api_notif_{$user->id}");
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
        $this->consultationImportService->queue($request->file('csv_file'), $user);

        return back()->with(
            'success',
            'File CSV berhasil diunggah ke antrean background. Proses import akan berjalan tanpa menahan browser.'
        );
    }

    public function downloadTemplate()
    {
        $fileName = 'template_import_leads.csv';
        $headers = [
            'Content-type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename=' . $fileName,
            'Pragma'              => 'no-cache',
            'Cache-Control'       => 'must-revalidate, post-check=0, pre-check=0',
            'Expires'             => '0'
        ];

        $columns = ['Nama Klien', 'No Telepon', 'ID Akun (Kosongkan jika Admin)'];

        $callback = function() use($columns) {
            $file = fopen('php://output', 'w');
            fputcsv($file, $columns);
            fputcsv($file, ['Budi Santoso', '081234567890', '1']);
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
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
