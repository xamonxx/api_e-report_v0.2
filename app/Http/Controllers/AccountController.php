<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\AccountRequest;
use App\Models\Account;
use App\Models\StatusCategory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class AccountController extends Controller
{
    public function index(Request $request)
    {
        $query = Account::query()
            ->withCount('consultations')
            ->with(['admins:id,name,account_id']);

        if ($dealStatusId = $this->resolveDealStatusId()) {
            $query->withCount([
                'consultations as deals_count' => fn ($builder) => $builder->where('status_category_id', $dealStatusId),
            ]);
        }

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        if ($request->filled('account_id')) {
            $query->where('id', $request->account_id);
        }

        if ($request->filled('category')) {
            $query->where('description', $request->category);
        }

        $accounts = $query->orderBy('name')->paginate(15)->appends($request->query());

        $categories = Account::select('description')
            ->whereNotNull('description')
            ->where('description', '!=', '')
            ->distinct()
            ->pluck('description');

        return view('accounts.index', compact('accounts', 'categories'));
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

    public function create()
    {
        return view('accounts.create');
    }

    /**
     * Store new account.
     *
     * Security:
     * - Uses AccountRequest (FormRequest) for validation
     * - DB transaction for atomicity
     * - Logo safely stored via Storage facade
     */
    public function store(AccountRequest $request)
    {
        $validated = $request->validated();

        DB::transaction(function () use ($request, &$validated) {
            if ($request->hasFile('logo')) {
                $validated['logo_path'] = $request->file('logo')->store('accounts', 'public');
            }

            unset($validated['logo'], $validated['remove_logo']);

            Account::create($validated);
        });

        return redirect()->route('accounts.index')
            ->with('success', 'Akun interior baru berhasil ditambahkan!');
    }

    public function edit(Account $account)
    {
        $admins = User::where('role', UserRole::Admin)
            ->where(function($q) use ($account) {
                $q->whereNull('account_id')
                  ->orWhere('account_id', $account->id);
            })
            ->get();
        return view('accounts.edit', compact('account', 'admins'));
    }

    /**
     * Update existing account.
     *
     * Security:
     * - DB transaction for atomicity
     * - Old logo properly deleted to prevent storage leak
     * - Uses whitelist via validated() only
     */
    public function update(AccountRequest $request, Account $account)
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

        return redirect()->route('accounts.index')
            ->with('success', 'Data akun berhasil diperbarui!');
    }

    /**
     * Delete account with relational safety.
     *
     * Security:
     * - Validates no active consultations exist before hard delete (if no soft delete)
     * - Detaches admin users safely
     * - DB transaction for atomicity
     * - Logo cleanup
     */
    public function destroy(Account $account)
    {
        // ── Check for active consultations ───────────────────────
        $activeConsultations = $account->consultations()->count();
        if ($activeConsultations > 0) {
            return back()->with('error',
                "Tidak dapat menghapus akun yang masih memiliki {$activeConsultations} data lead. " .
                'Hapus atau pindahkan seluruh lead terlebih dahulu.'
            );
        }

        DB::transaction(function () use ($account) {
            // Detach admin users from this account
            User::where('role', UserRole::Admin)
                ->where('account_id', $account->id)
                ->update(['account_id' => null]);

            // Delete logo file
            if ($account->logo_path) {
                Storage::disk('public')->delete($account->logo_path);
            }

            $account->delete();
        });

        return redirect()->route('accounts.index')
            ->with('success', 'Akun berhasil dihapus.');
    }
}
