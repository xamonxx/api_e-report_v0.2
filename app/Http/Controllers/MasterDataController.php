<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\NeedsCategory;
use App\Models\StatusCategory;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Enum;
use Throwable;

class MasterDataController extends Controller
{
    private function usersTabRouteParams(Request $request, array $overrides = []): array
    {
        $params = array_merge([
            'tab' => 'users',
            'search_user' => $request->input('search_user'),
            'users_page' => $request->input('users_page'),
        ], $overrides);

        return array_filter(
            $params,
            fn ($value, $key) => $key === 'tab' || $value === 0 || $value === '0' || filled($value),
            ARRAY_FILTER_USE_BOTH
        );
    }

    private function redirectToUsersTab(Request $request, array $overrides = []): RedirectResponse
    {
        return redirect()->route('master-data.index', $this->usersTabRouteParams($request, $overrides));
    }

    private function buildUserAuditSnapshot(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role instanceof UserRole ? $user->role->value : $user->role,
            'account_id' => $user->account_id,
            'account_name' => $user->account?->name,
            'created_by' => $user->created_by,
            'updated_by' => $user->updated_by,
            'deleted_by' => $user->deleted_by,
            'created_at' => $user->created_at?->toDateTimeString(),
            'updated_at' => $user->updated_at?->toDateTimeString(),
            'deleted_at' => $user->deleted_at?->toDateTimeString(),
        ];
    }

    private function roleLabel(UserRole $role): string
    {
        return $role === UserRole::SuperAdmin ? 'Super Admin' : 'Admin';
    }

    public function index(Request $request)
    {
        $tab = $request->get('tab', 'categories');

        $categories = NeedsCategory::forConsultationOptions()->paginate(10, ['*'], 'categories_page');

        $statuses = StatusCategory::orderBy('sort_order')->paginate(10, ['*'], 'statuses_page');

        $userQuery = User::with('account')->orderBy('name');
        if ($request->filled('search_user')) {
            $search = $request->search_user;
            $userQuery->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhereHas('account', function ($aq) use ($search) {
                        $aq->where('name', 'like', "%{$search}%");
                    });
            });
        }

        $users = $userQuery->paginate(10, ['*'], 'users_page')->appends([
            'tab' => 'users',
            'search_user' => $request->search_user,
        ]);

        $accounts = Account::orderBy('name')->get();

        return view('master-data.index', compact('tab', 'categories', 'statuses', 'users', 'accounts'));
    }

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
        if ($category->consultations()->withTrashed()->exists()) {
            return back()->with('error', 'Tidak dapat menghapus kategori yang masih digunakan (meskipun berada di trash).');
        }

        $category->delete();

        return back()->with('success', 'Kategori berhasil dihapus!');
    }

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
        if ($status->consultations()->withTrashed()->exists()) {
            return back()->with('error', 'Tidak dapat menghapus status yang masih digunakan (meskipun berada di trash).');
        }

        $status->delete();

        return back()->with('success', 'Status berhasil dihapus!');
    }

    public function storeUser(Request $request)
    {
        $validator = Validator::make($request->all(), [
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

        if ($validator->fails()) {
            return $this->redirectToUsersTab($request)
                ->withErrors($validator, 'createUser')
                ->withInput($request->except('password'))
                ->with('error', $validator->errors()->first())
                ->with('user_form_context', 'create');
        }

        $validated = $validator->validated();
        $role = UserRole::from($validated['role']);

        try {
            $user = DB::transaction(function () use ($validated, $role) {
                $createdUser = User::create([
                    'name' => trim($validated['name']),
                    'email' => mb_strtolower(trim($validated['email'])),
                    'password' => Hash::make($validated['password']),
                    'role' => $role,
                    'account_id' => $role === UserRole::SuperAdmin ? null : $validated['account_id'],
                ]);

                $createdUser->loadMissing('account');

                AuditLog::logCreated(
                    $createdUser,
                    sprintf(
                        'Menambahkan user %s dengan role %s.',
                        $createdUser->name,
                        $this->roleLabel($role)
                    )
                );

                return $createdUser;
            });
        } catch (Throwable $exception) {
            report($exception);

            return $this->redirectToUsersTab($request)
                ->withInput($request->except('password'))
                ->with('error', 'User baru gagal ditambahkan. Silakan coba lagi.')
                ->with('user_form_context', 'create');
        }

        return $this->redirectToUsersTab($request)
            ->with('success', "User {$user->name} berhasil ditambahkan!");
    }

    public function updateUser(Request $request, User $user)
    {
        $validator = Validator::make($request->all(), [
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

        if ($validator->fails()) {
            return $this->redirectToUsersTab($request)
                ->withErrors($validator, 'editUser')
                ->withInput()
                ->with('error', $validator->errors()->first())
                ->with('user_form_context', 'edit');
        }

        $validated = $validator->validated();

        if ((int) $validated['edit_user_id'] !== $user->id) {
            return $this->redirectToUsersTab($request)
                ->withInput()
                ->with('error', 'Data user yang akan diperbarui tidak valid.')
                ->with('user_form_context', 'edit');
        }

        $role = UserRole::from($validated['role']);

        if (
            $user->role === UserRole::SuperAdmin
            && $role !== UserRole::SuperAdmin
            && User::where('role', UserRole::SuperAdmin)->count() <= 1
        ) {
            return $this->redirectToUsersTab($request)
                ->withInput()
                ->with('error', 'Tidak dapat mengubah Super Admin terakhir menjadi Admin biasa.')
                ->with('user_form_context', 'edit');
        }

        try {
            DB::transaction(function () use ($user, $validated, $role) {
                $user->loadMissing('account');
                $oldValues = $this->buildUserAuditSnapshot($user);

                $user->update([
                    'name' => trim($validated['name']),
                    'email' => mb_strtolower(trim($validated['email'])),
                    'role' => $role,
                    'account_id' => $role === UserRole::SuperAdmin ? null : $validated['account_id'],
                ]);

                $user->refresh()->loadMissing('account');

                AuditLog::logUpdated(
                    $user,
                    $oldValues,
                    sprintf(
                        'Memperbarui user %s menjadi role %s.',
                        $user->name,
                        $this->roleLabel($role)
                    )
                );
            });
        } catch (Throwable $exception) {
            report($exception);

            return $this->redirectToUsersTab($request)
                ->withInput()
                ->with('error', "Data user {$user->name} gagal diperbarui. Silakan coba lagi.")
                ->with('user_form_context', 'edit');
        }

        return $this->redirectToUsersTab($request)
            ->with('success', "Data user {$user->name} berhasil diperbarui!");
    }

    public function destroyUser(Request $request, User $user)
    {
        if ($user->id === auth()->id()) {
            return $this->redirectToUsersTab($request)
                ->with('error', 'Tidak dapat menghapus akun Anda sendiri.');
        }

        if ($user->role === UserRole::SuperAdmin && User::where('role', UserRole::SuperAdmin)->count() <= 1) {
            return $this->redirectToUsersTab($request)
                ->with('error', 'Tidak dapat menghapus Super Admin terakhir pada sistem.');
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
        } catch (Throwable $exception) {
            report($exception);

            return $this->redirectToUsersTab($request)
                ->with('error', "User {$user->name} gagal dihapus. Silakan coba lagi.");
        }

        return $this->redirectToUsersTab($request)
            ->with('success', 'User berhasil dihapus!');
    }

    public function resetUserPassword(Request $request, User $user)
    {
        $validator = Validator::make($request->all(), [
            'password' => 'required|string|min:8',
        ], [
            'password.required' => 'Password baru wajib diisi.',
            'password.min' => 'Password minimal 8 karakter.',
        ]);

        if ($validator->fails()) {
            return $this->redirectToUsersTab($request)
                ->with('error', $validator->errors()->first());
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
        } catch (Throwable $exception) {
            report($exception);

            return $this->redirectToUsersTab($request)
                ->with('error', "Password untuk {$user->name} gagal direset. Silakan coba lagi.");
        }

        return $this->redirectToUsersTab($request)
            ->with('success', "Password untuk {$user->name} berhasil direset!");
    }
}
