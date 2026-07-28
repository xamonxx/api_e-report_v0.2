<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\AccountRequest;
use App\Models\Account;
use App\Models\StatusCategory;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AccountController extends Controller
{
    /**
     * GET /api/v1/accounts
     */
    /**
     * GET /api/v1/accounts/categories
     *
     * Daftar kategori akun unik (kolom description) untuk mengisi dropdown
     * filter pada halaman Kelola Akun.
     */
    public function categories(): JsonResponse
    {
        $categories = Account::query()
            ->whereNotNull('description')
            ->where('description', '!=', '')
            ->distinct()
            ->orderBy('description')
            ->pluck('description')
            ->values();

        return response()->json(['data' => $categories]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Account::query()
            ->withCount('consultations')
            ->with(['admins:id,name,account_id']);

        if ($dealStatusId = $this->resolveDealStatusId()) {
            $query->withCount([
                'consultations as deals_count' => fn ($builder) => $builder->where('status_category_id', $dealStatusId),
            ]);
        }

        if ($surveyStatusId = $this->resolveRequestSurveyStatusId()) {
            $query->withCount([
                'consultations as surveys_count' => fn ($builder) => $builder->where('status_category_id', $surveyStatusId),
            ]);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('admins', function ($aq) use ($search) {
                        $aq->where('name', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->filled('category')) {
            $query->where('description', $request->category);
        }

        $perPage = (int) $request->input('per_page', 20);
        $perPage = $perPage > 0 ? min($perPage, 100) : 20;

        $accounts = $query->orderBy('name')->paginate($perPage);

        return response()->json([
            'data' => $accounts->items(),
            'meta' => [
                'current_page' => $accounts->currentPage(),
                'last_page' => $accounts->lastPage(),
                'per_page' => $accounts->perPage(),
                'total' => $accounts->total(),
            ],
        ]);
    }

    /**
     * POST /api/v1/accounts
     */
    public function store(AccountRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $account = null;

        DB::transaction(function () use ($request, &$validated, &$account) {
            if ($request->hasFile('logo')) {
                $validated['logo_path'] = $request->file('logo')->store('accounts', 'public');
            }

            unset($validated['logo'], $validated['remove_logo']);

            $account = Account::create($validated);
        });

        return response()->json([
            'message' => 'Akun interior baru berhasil ditambahkan!',
            'data' => $account,
        ], 201);
    }

    /**
     * GET /api/v1/accounts/{account}
     */
    public function show(Account $account): JsonResponse
    {
        $account->load(['admins:id,name,account_id']);

        if ($dealStatusId = $this->resolveDealStatusId()) {
            $account->loadCount([
                'consultations',
                'consultations as deals_count' => fn ($builder) => $builder->where('status_category_id', $dealStatusId),
            ]);
        }

        if ($surveyStatusId = $this->resolveRequestSurveyStatusId()) {
            $account->loadCount([
                'consultations as surveys_count' => fn ($builder) => $builder->where('status_category_id', $surveyStatusId),
            ]);
        }

        return response()->json([
            'data' => $account,
        ]);
    }

    /**
     * POST /api/v1/accounts/{account} (using POST with _method=PUT to support file uploads)
     */
    public function update(AccountRequest $request, Account $account): JsonResponse
    {
        $validated = $request->validated();

        DB::transaction(function () use ($request, &$validated, $account) {
            if ($request->boolean('remove_logo')) {
                if ($account->logo_path) {
                    Storage::disk('public')->delete($account->logo_path);
                    $validated['logo_path'] = null;
                }
            } elseif ($request->hasFile('logo') && $request->file('logo')->isValid()) {
                if ($account->logo_path) {
                    Storage::disk('public')->delete($account->logo_path);
                }
                $validated['logo_path'] = $request->file('logo')->store('accounts', 'public');
            }

            unset($validated['logo'], $validated['remove_logo']);

            $account->update($validated);
        });

        return response()->json([
            'message' => 'Data akun berhasil diperbarui!',
            'data' => $account,
        ]);
    }

    /**
     * DELETE /api/v1/accounts/{account}
     */
    public function destroy(Account $account): JsonResponse
    {
        $totalConsultations = $account->consultations()->withTrashed()->count();
        if ($totalConsultations > 0) {
            return response()->json([
                'message' => "Tidak dapat menghapus akun yang masih memiliki {$totalConsultations} data lead (termasuk yang sudah dihapus). Hapus permanen atau pindahkan seluruh lead terlebih dahulu.",
            ], 422);
        }

        DB::transaction(function () use ($account) {
            User::where('role', UserRole::Admin)
                ->where('account_id', $account->id)
                ->update(['account_id' => null]);

            if ($account->logo_path) {
                Storage::disk('public')->delete($account->logo_path);
            }

            $account->delete();
        });

        return response()->json([
            'message' => 'Akun berhasil dihapus.',
        ]);
    }

    private function resolveDealStatusId(): ?int
    {
        static $dealStatusId;
        static $resolved = false;

        if ($resolved) {
            return $dealStatusId;
        }

        $dealStatusId = StatusCategory::query()
            ->whereIn('name', array_filter([
                config('statuses.deal'),
                'Selesai/Deal',
                'Selesai Deal',
            ]))
            ->value('id');
        $resolved = true;

        return $dealStatusId;
    }

    private function resolveRequestSurveyStatusId(): ?int
    {
        static $surveyStatusId;
        static $resolved = false;

        if ($resolved) {
            return $surveyStatusId;
        }

        $surveyStatusId = StatusCategory::query()
            ->whereIn('name', array_filter([
                config('statuses.request_survey'),
                'Request Survey',
                'Request survey',
            ]))
            ->value('id');
        $resolved = true;

        return $surveyStatusId;
    }
}
