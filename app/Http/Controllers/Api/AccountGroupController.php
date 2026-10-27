<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AccountGroupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = AccountGroup::query()
            ->withCount('accounts')
            ->ordered();

        if ($request->boolean('active_only')) {
            $query->active();
        }

        return response()->json(['data' => $query->get()->map(fn (AccountGroup $group) => $this->payload($group))]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', 'regex:/^[^<>]+$/', Rule::unique('account_groups', 'name')],
        ], $this->messages());

        $group = DB::transaction(function () use ($validated) {
            $group = AccountGroup::create([
                'code' => 'TMP'.str()->upper(str()->random(7)),
                'name' => trim($validated['name']),
                'sort_order' => ((int) AccountGroup::max('sort_order')) + 10,
                'is_active' => true,
            ]);
            $group->update(['code' => 'G'.str_pad((string) $group->id, 9, '0', STR_PAD_LEFT)]);

            return $group;
        });

        return response()->json([
            'message' => 'Grup akun berhasil ditambahkan.',
            'data' => $this->payload($group->loadCount('accounts')),
        ], 201);
    }

    public function update(Request $request, AccountGroup $accountGroup): JsonResponse
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                'regex:/^[^<>]+$/',
                Rule::unique('account_groups', 'name')->ignore($accountGroup->id),
            ],
        ], $this->messages());

        $accountGroup->update(['name' => trim($validated['name'])]);

        return response()->json([
            'message' => 'Nama grup berhasil diperbarui. Histori tetap memakai identitas grup yang sama.',
            'data' => $this->payload($accountGroup->fresh()->loadCount('accounts')),
        ]);
    }

    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['integer', 'distinct', 'exists:account_groups,id'],
        ]);

        DB::transaction(function () use ($validated) {
            foreach ($validated['order'] as $index => $id) {
                AccountGroup::whereKey($id)->update(['sort_order' => ($index + 1) * 10]);
            }
        });

        return response()->json([
            'message' => 'Urutan grup berhasil diperbarui.',
            'data' => AccountGroup::query()->withCount('accounts')->ordered()->get()
                ->map(fn (AccountGroup $group) => $this->payload($group)),
        ]);
    }

    public function status(Request $request, AccountGroup $accountGroup): JsonResponse
    {
        $validated = $request->validate(['is_active' => ['required', 'boolean']]);
        $isActive = (bool) $validated['is_active'];

        if (! $isActive && $accountGroup->accounts()->exists()) {
            return response()->json([
                'message' => 'Grup masih memiliki akun aktif. Pindahkan seluruh akun sebelum mengarsipkan grup.',
            ], 422);
        }

        $accountGroup->update(['is_active' => $isActive]);

        return response()->json([
            'message' => $isActive ? 'Grup berhasil dipulihkan.' : 'Grup berhasil diarsipkan.',
            'data' => $this->payload($accountGroup->fresh()->loadCount('accounts')),
        ]);
    }

    private function messages(): array
    {
        return [
            'name.required' => 'Nama grup wajib diisi.',
            'name.max' => 'Nama grup maksimal 100 karakter.',
            'name.unique' => 'Nama grup sudah digunakan.',
            'name.regex' => 'Nama grup tidak boleh mengandung tag HTML.',
        ];
    }

    private function payload(AccountGroup $group): array
    {
        return [
            'id' => $group->id,
            'code' => $group->code,
            'name' => $group->name,
            'value' => $group->code,
            'label' => $group->name,
            'subtitle' => $group->name,
            'sort_order' => $group->sort_order,
            'is_active' => $group->is_active,
            'accounts_count' => $group->accounts_count ?? $group->accounts()->count(),
        ];
    }
}
