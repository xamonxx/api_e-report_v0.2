<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\NeedsCategory;
use App\Models\StatusCategory;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Enum;
use Throwable;

class MasterDataController extends Controller
{
    /**
     * GET /api/v1/master-data/needs-categories
     */
    public function needsCategories(): JsonResponse
    {
        $categories = NeedsCategory::forConsultationOptions()
            ->get(['id', 'name']);

        return response()->json([
            'data' => $categories,
        ])->header('Cache-Control', 'public, max-age=3600, s-maxage=3600');
    }

    /**
     * GET /api/v1/master-data/status-categories
     */
    public function statusCategories(): JsonResponse
    {
        $statuses = StatusCategory::orderBy('sort_order')
            ->get(['id', 'name', 'css_class', 'color', 'sort_order']);

        return response()->json([
            'data' => $statuses,
        ])->header('Cache-Control', 'public, max-age=3600, s-maxage=3600');
    }

    /**
     * GET /api/v1/master-data/accounts
     */
    public function accounts(): JsonResponse
    {
        $user = auth()->user();
        $accounts = $user->isSuperAdmin()
            ? Account::orderBy('name')->get(['id', 'name'])
            : Account::whereKey($user->account_id)->get(['id', 'name']);

        return response()->json([
            'data' => $accounts,
        ]);
    }

    // ── Super Admin Master Data CRUD ─────────────────────────────

    /**
     * GET /api/v1/master-data/categories/list
     */
    public function listCategories(): JsonResponse
    {
        $this->ensureSuperAdmin();
        $categories = NeedsCategory::forConsultationOptions()->paginate(15);
        return response()->json($categories);
    }

    /**
     * POST /api/v1/master-data/categories
     */
    public function storeCategory(Request $request): JsonResponse
    {
        $this->ensureSuperAdmin();
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:needs_categories,name|regex:/^[^\<\>]+$/',
        ], [
            'name.required' => 'Nama kategori wajib diisi.',
            'name.max' => 'Nama kategori terlalu panjang (maksimal 255 karakter).',
            'name.unique' => 'Nama kategori sudah ada.',
            'name.regex' => 'Nama kategori tidak boleh mengandung tag HTML.',
        ]);

        $category = NeedsCategory::create(['name' => trim($validated['name'])]);

        return response()->json([
            'message' => 'Kategori kebutuhan berhasil ditambahkan!',
            'data' => $category,
        ], 201);
    }

    /**
     * PUT /api/v1/master-data/categories/{category}
     */
    public function updateCategory(Request $request, NeedsCategory $category): JsonResponse
    {
        $this->ensureSuperAdmin();
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:needs_categories,name,' . $category->id . '|regex:/^[^\<\>]+$/',
        ], [
            'name.required' => 'Nama kategori wajib diisi.',
            'name.max' => 'Nama kategori terlalu panjang (maksimal 255 karakter).',
            'name.unique' => 'Nama kategori sudah ada.',
            'name.regex' => 'Nama kategori tidak boleh mengandung tag HTML.',
        ]);

        $category->update(['name' => trim($validated['name'])]);

        return response()->json([
            'message' => 'Kategori berhasil diperbarui!',
            'data' => $category,
        ]);
    }

    /**
     * DELETE /api/v1/master-data/categories/{category}
     */
    public function destroyCategory(NeedsCategory $category): JsonResponse
    {
        $this->ensureSuperAdmin();
        if ($category->consultations()->withTrashed()->exists()) {
            return response()->json([
                'message' => 'Tidak dapat menghapus kategori yang masih digunakan.',
            ], 422);
        }

        $category->delete();

        return response()->json([
            'message' => 'Kategori berhasil dihapus!',
        ]);
    }

    /**
     * GET /api/v1/master-data/statuses/list
     */
    public function listStatuses(): JsonResponse
    {
        $this->ensureSuperAdmin();
        $statuses = StatusCategory::orderBy('sort_order')->paginate(15);
        return response()->json($statuses);
    }

    /**
     * POST /api/v1/master-data/statuses
     */
    public function storeStatus(Request $request): JsonResponse
    {
        $this->ensureSuperAdmin();
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:status_categories,name|regex:/^[^\<\>]+$/',
            'color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ], [
            'name.required' => 'Nama status wajib diisi.',
            'name.max' => 'Nama status terlalu panjang (maksimal 255 karakter).',
            'name.unique' => 'Nama status sudah ada.',
            'name.regex' => 'Nama status tidak boleh mengandung tag HTML.',
            'color.required' => 'Warna wajib dipilih.',
            'color.regex' => 'Format warna harus berupa hex (contoh: #FF5733).',
        ]);

        $maxOrder = StatusCategory::max('sort_order') ?? 0;
        $status = StatusCategory::create([
            'name' => trim($validated['name']),
            'color' => $validated['color'],
            'sort_order' => $maxOrder + 1,
        ]);

        return response()->json([
            'message' => 'Status berhasil ditambahkan!',
            'data' => $status,
        ], 201);
    }

    /**
     * PUT /api/v1/master-data/statuses/{status}
     */
    public function updateStatus(Request $request, StatusCategory $status): JsonResponse
    {
        $this->ensureSuperAdmin();
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:status_categories,name,' . $status->id . '|regex:/^[^\<\>]+$/',
            'color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ], [
            'name.required' => 'Nama status wajib diisi.',
            'name.max' => 'Nama status terlalu panjang (maksimal 255 karakter).',
            'name.unique' => 'Nama status sudah ada.',
            'name.regex' => 'Nama status tidak boleh mengandung tag HTML.',
            'color.required' => 'Warna wajib dipilih.',
            'color.regex' => 'Format warna harus berupa hex (contoh: #FF5733).',
        ]);

        $status->update([
            'name' => trim($validated['name']),
            'color' => $validated['color'],
        ]);

        return response()->json([
            'message' => 'Status berhasil diperbarui!',
            'data' => $status,
        ]);
    }

    /**
     * DELETE /api/v1/master-data/statuses/{status}
     */
    public function destroyStatus(StatusCategory $status): JsonResponse
    {
        $this->ensureSuperAdmin();
        if ($status->consultations()->withTrashed()->exists()) {
            return response()->json([
                'message' => 'Tidak dapat menghapus status yang masih digunakan.',
            ], 422);
        }

        $status->delete();

        return response()->json([
            'message' => 'Status berhasil dihapus!',
        ]);
    }

    /**
     * GET /api/v1/master-data/users
     */
    public function listUsers(Request $request): JsonResponse
    {
        $this->ensureSuperAdmin();
        $userQuery = User::with('account')->orderBy('name');

        if ($request->filled('search')) {
            $search = $request->search;
            $userQuery->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhereHas('account', function ($aq) use ($search) {
                        $aq->where('name', 'like', "%{$search}%");
                    });
            });
        }

        $users = $userQuery->paginate(15);
        return response()->json($users);
    }

    /**
     * POST /api/v1/master-data/users
     */
    public function storeUser(Request $request): JsonResponse
    {
        $this->ensureSuperAdmin();
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|regex:/^[^\<\>]+$/',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8',
            'password_confirmation' => 'required|string|same:password',
            'role' => ['required', new Enum(UserRole::class)],
            'account_id' => 'required_if:role,' . UserRole::Admin->value . '|nullable|exists:accounts,id',
        ], [
            'name.required' => 'Nama user wajib diisi.',
            'name.regex' => 'Nama user tidak boleh mengandung tag HTML.',
            'email.required' => 'Email wajib diisi.',
            'email.email' => 'Format email tidak valid.',
            'email.unique' => 'Email sudah digunakan.',
            'password.required' => 'Password wajib diisi.',
            'password.min' => 'Password minimal 8 karakter.',
            'password_confirmation.required' => 'Konfirmasi password wajib diisi.',
            'password_confirmation.same' => 'Konfirmasi password tidak cocok.',
            'account_id.required_if' => 'Akun wajib dipilih untuk pengguna dengan role Admin.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $role = UserRole::from($validated['role']);

        try {
            $user = DB::transaction(function () use ($validated, $role) {
                // F-014: role assigned explicitly (not via mass-assignment) to prevent
                // privilege escalation if $fillable is ever inadvertently relaxed.
                $createdUser = User::create([
                    'name' => trim($validated['name']),
                    'email' => mb_strtolower(trim($validated['email'])),
                    'password' => Hash::make($validated['password']),
                    'account_id' => $role === UserRole::SuperAdmin ? null : $validated['account_id'],
                ]);
                $createdUser->role = $role;
                $createdUser->save();

                $createdUser->loadMissing('account');

                AuditLog::logCreated(
                    $createdUser,
                    sprintf('Menambahkan user %s dengan role %s.', $createdUser->name, $role->value)
                );

                return $createdUser;
            });

            return response()->json([
                'message' => "User {$user->name} berhasil ditambahkan!",
                'data' => $user,
            ], 201);

        } catch (Throwable $exception) {
            report($exception);
            return response()->json(['message' => 'User baru gagal ditambahkan. Silakan coba lagi.'], 500);
        }
    }

    /**
     * PUT /api/v1/master-data/users/{user}
     */
    public function updateUser(Request $request, User $user): JsonResponse
    {
        $this->ensureSuperAdmin();
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|regex:/^[^\<\>]+$/',
            'email' => 'required|email|max:255|unique:users,email,' . $user->id,
            'role' => ['required', new Enum(UserRole::class)],
            'account_id' => 'required_if:role,' . UserRole::Admin->value . '|nullable|exists:accounts,id',
        ], [
            'name.required' => 'Nama user wajib diisi.',
            'name.regex' => 'Nama user tidak boleh mengandung tag HTML.',
            'email.required' => 'Email wajib diisi.',
            'email.email' => 'Format email tidak valid.',
            'email.unique' => 'Email sudah digunakan user lain.',
            'account_id.required_if' => 'Akun wajib dipilih untuk pengguna dengan role Admin.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $role = UserRole::from($validated['role']);

        if (
            $user->role === UserRole::SuperAdmin
            && $role !== UserRole::SuperAdmin
            && User::where('role', UserRole::SuperAdmin)->count() <= 1
        ) {
            return response()->json([
                'message' => 'Tidak dapat mengubah Super Admin terakhir menjadi Admin biasa.',
            ], 422);
        }

        try {
            DB::transaction(function () use ($user, $validated, $role) {
                $user->loadMissing('account');
                $oldValues = [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role instanceof UserRole ? $user->role->value : $user->role,
                    'account_id' => $user->account_id,
                ];

                // F-014: role assigned explicitly (not via mass-assignment) to prevent
                // privilege escalation if $fillable is ever inadvertently relaxed.
                $user->update([
                    'name' => trim($validated['name']),
                    'email' => mb_strtolower(trim($validated['email'])),
                    'account_id' => $role === UserRole::SuperAdmin ? null : $validated['account_id'],
                ]);
                $user->role = $role;
                $user->save();

                $user->refresh()->loadMissing('account');

                AuditLog::logUpdated(
                    $user,
                    $oldValues,
                    sprintf('Memperbarui user %s menjadi role %s.', $user->name, $role->value)
                );
            });

            return response()->json([
                'message' => "Data user {$user->name} berhasil diperbarui!",
                'data' => $user,
            ]);

        } catch (Throwable $exception) {
            report($exception);
            return response()->json(['message' => "Data user {$user->name} gagal diperbarui. Silakan coba lagi."], 500);
        }
    }

    /**
     * DELETE /api/v1/master-data/users/{user}
     */
    public function destroyUser(User $user): JsonResponse
    {
        $this->ensureSuperAdmin();
        if ($user->id === auth()->id()) {
            return response()->json(['message' => 'Tidak dapat menghapus akun Anda sendiri.'], 422);
        }

        if ($user->role === UserRole::SuperAdmin && User::where('role', UserRole::SuperAdmin)->count() <= 1) {
            return response()->json(['message' => 'Tidak dapat menghapus Super Admin terakhir pada sistem.'], 422);
        }

        try {
            DB::transaction(function () use ($user) {
                $user->loadMissing('account');
                $user->delete();

                AuditLog::logDeleted(
                    $user,
                    sprintf('Menghapus user %s dari sistem.', $user->name)
                );
            });

            return response()->json([
                'message' => 'User berhasil dihapus!',
            ]);
        } catch (Throwable $exception) {
            report($exception);
            return response()->json(['message' => "User {$user->name} gagal dihapus. Silakan coba lagi."], 500);
        }
    }

    /**
     * POST /api/v1/master-data/users/{user}/reset-password
     */
    public function resetUserPassword(Request $request, User $user): JsonResponse
    {
        $this->ensureSuperAdmin();
        $validator = Validator::make($request->all(), [
            'password' => 'required|string|min:8',
        ], [
            'password.required' => 'Password baru wajib diisi.',
            'password.min' => 'Password minimal 8 karakter.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::transaction(function () use ($request, $user) {
                $user->update([
                    'password' => Hash::make($request->password),
                ]);

                AuditLog::logUpdated(
                    $user->fresh(),
                    ['password' => '[REDACTED]'],
                    sprintf('Mereset password user %s.', $user->name)
                );
            });

            return response()->json([
                'message' => "Password untuk {$user->name} berhasil direset!",
            ]);
        } catch (Throwable $exception) {
            report($exception);
            return response()->json(['message' => "Password untuk {$user->name} gagal direset. Silakan coba lagi."], 500);
        }
    }

    private function ensureSuperAdmin(): void
    {
        abort_if(! auth()->user()?->isSuperAdmin(), 403, 'Unauthorized. Super Admin role required.');
    }
}
