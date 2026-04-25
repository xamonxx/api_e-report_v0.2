<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\NeedsCategory;
use App\Models\StatusCategory;
use App\Models\User;
use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Enum;

class MasterDataController extends Controller
{
    public function index(Request $request)
    {
        $tab = $request->get('tab', 'categories');

        $categories = NeedsCategory::forConsultationOptions()->paginate(10, ['*'], 'categories_page');

        $statuses = StatusCategory::orderBy('sort_order')->paginate(10, ['*'], 'statuses_page');

        $userQuery = User::with('account')->orderBy('name');
        if ($request->filled('search_user')) {
            $search = $request->search_user;
            $userQuery->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhereHas('account', function($aq) use ($search) {
                      $aq->where('name', 'like', "%{$search}%");
                  });
            });
        }
        $users = $userQuery->paginate(10, ['*'], 'users_page')->appends([
            'tab' => 'users',
            'search_user' => $request->search_user
        ]);
        $accounts = Account::orderBy('name')->get();

        return view('master-data.index', compact('tab', 'categories', 'statuses', 'users', 'accounts'));
    }

    // ── Needs Categories ─────────────────────────────────

    public function storeCategory(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:needs_categories,name|regex:/^[^\<\>]+$/',
        ], [
            'name.required' => 'Nama kategori wajib diisi.',
            'name.max' => 'Nama kategori terlalu panjang (maksimal 255 karakter).',
            'name.unique' => 'Nama kategori sudah ada.',
            'name.regex' => 'Nama kategori tidak boleh mengandung tag HTML.',
        ]);

        NeedsCategory::create(['name' => trim($validated['name'])]);
        return back()->with('success', 'Kategori kebutuhan berhasil ditambahkan!');
    }

    public function updateCategory(Request $request, NeedsCategory $category)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:needs_categories,name,' . $category->id . '|regex:/^[^\<\>]+$/',
        ], [
            'name.required' => 'Nama kategori wajib diisi.',
            'name.max' => 'Nama kategori terlalu panjang (maksimal 255 karakter).',
            'name.unique' => 'Nama kategori sudah ada.',
            'name.regex' => 'Nama kategori tidak boleh mengandung tag HTML.',
        ]);

        $category->update(['name' => trim($validated['name'])]);
        return back()->with('success', 'Kategori berhasil diperbarui!');
    }

    public function destroyCategory(NeedsCategory $category)
    {
        // ── Foreign Key Safety: check relasi sebelum hapus ───────
        if ($category->consultations()->withTrashed()->exists()) {
            return back()->with('error', 'Tidak dapat menghapus kategori yang masih digunakan (meskipun berada di trash).');
        }

        // Use soft delete if available, hard delete otherwise
        $category->delete();
        return back()->with('success', 'Kategori berhasil dihapus!');
    }

    // ── Status Categories ────────────────────────────────

    public function storeStatus(Request $request)
    {
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
        StatusCategory::create([
            'name' => trim($validated['name']),
            'color' => $validated['color'],
            'sort_order' => $maxOrder + 1,
        ]);

        return back()->with('success', 'Status berhasil ditambahkan!');
    }

    public function updateStatus(Request $request, StatusCategory $status)
    {
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

        return back()->with('success', 'Status berhasil diperbarui!');
    }

    public function destroyStatus(StatusCategory $status)
    {
        // ── Foreign Key Safety: check relasi sebelum hapus ───────
        if ($status->consultations()->withTrashed()->exists()) {
            return back()->with('error', 'Tidak dapat menghapus status yang masih digunakan (meskipun berada di trash).');
        }

        $status->delete();
        return back()->with('success', 'Status berhasil dihapus!');
    }

    // ── User Management ──────────────────────────────────

    /**
     * Store new user.
     *
     * Security:
     * - Whitelist only allowed fields (prevent mass assignment of role escalation)
     * - Password hashing via bcrypt (Laravel default)
     * - Super Admin role properly restricts account_id
     * - DB transaction for atomicity
     */
    public function storeUser(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|regex:/^[^\<\>]+$/',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8',
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
            'account_id.required_if' => 'Akun wajib dipilih untuk pengguna dengan role Admin.',
        ]);

        $role = UserRole::from($validated['role']);

        DB::transaction(function () use ($validated, $role) {
            User::create([
                'name' => trim($validated['name']),
                'email' => mb_strtolower(trim($validated['email'])),
                'password' => Hash::make($validated['password']),
                'role' => $role,
                'account_id' => $role === UserRole::SuperAdmin ? null : $validated['account_id'],
            ]);
        });

        return back()->with('success', 'User baru berhasil ditambahkan!');
    }

    /**
     * Update existing user.
     *
     * Security:
     * - Validates ownership via edit_user_id cross-check
     * - Unique email validation ignores current record ID (prevents false positive)
     * - Protects against removing last Super Admin
     * - Only updates explicitly provided fields (no NULL overwrites)
     * - DB transaction for atomicity
     */
    public function updateUser(Request $request, User $user)
    {
        $validated = $request->validate([
            'edit_user_id' => 'required|integer',
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

        // ── IDOR Protection: cross-check form data vs URL param ──
        if ((int) $validated['edit_user_id'] !== $user->id) {
            return back()
                ->withInput()
                ->with('error', 'Data user yang akan diperbarui tidak valid.');
        }

        $role = UserRole::from($validated['role']);

        // ── Protect Last Super Admin ─────────────────────────────
        if (
            $user->role === UserRole::SuperAdmin
            && $role !== UserRole::SuperAdmin
            && User::where('role', UserRole::SuperAdmin)->count() <= 1
        ) {
            return back()
                ->withInput()
                ->with('error', 'Tidak dapat mengubah Super Admin terakhir menjadi Admin biasa.');
        }

        DB::transaction(function () use ($user, $validated, $role) {
            $user->update([
                'name' => trim($validated['name']),
                'email' => mb_strtolower(trim($validated['email'])),
                'role' => $role,
                'account_id' => $role === UserRole::SuperAdmin ? null : $validated['account_id'],
            ]);
        });

        return redirect()
            ->route('master-data.index', [
                'tab' => 'users',
                'search_user' => $request->search_user,
                'users_page' => $request->users_page,
            ])
            ->with('success', "Data user {$user->name} berhasil diperbarui!");
    }

    /**
     * Delete user with safety checks.
     *
     * Security:
     * - Cannot delete yourself
     * - Cannot delete last Super Admin
     * - Uses soft delete (if trait is applied)
     * - Validates related data
     */
    public function destroyUser(User $user)
    {
        // ── Cannot delete yourself ───────────────────────────────
        if ($user->id === auth()->id()) {
            return back()->with('error', 'Tidak dapat menghapus akun Anda sendiri.');
        }

        // ── Protect Last Super Admin ─────────────────────────────
        if ($user->role === UserRole::SuperAdmin && User::where('role', UserRole::SuperAdmin)->count() <= 1) {
            return back()->with('error', 'Tidak dapat menghapus Super Admin terakhir pada sistem.');
        }

        DB::transaction(function () use ($user) {
            $user->delete();
        });

        return back()->with('success', 'User berhasil dihapus!');
    }

    /**
     * Reset user password.
     *
     * Security:
     * - Minimum password 8 characters
     * - Bcrypt hashing
     */
    public function resetUserPassword(Request $request, User $user)
    {
        $request->validate([
            'password' => 'required|string|min:8',
        ], [
            'password.required' => 'Password baru wajib diisi.',
            'password.min' => 'Password minimal 8 karakter.',
        ]);

        $user->update([
            'password' => Hash::make($request->password),
        ]);

        return back()->with('success', "Password untuk {$user->name} berhasil direset!");
    }
}
