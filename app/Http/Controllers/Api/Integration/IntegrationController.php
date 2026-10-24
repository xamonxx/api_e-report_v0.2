<?php

namespace App\Http\Controllers\Api\Integration;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Consultation;
use App\Models\IntegrationClient;
use App\Models\NeedsCategory;
use App\Models\StatusCategory;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only data feed for external systems. Every query is limited to the
 * accounts on the calling integration client's allowlist.
 */
class IntegrationController extends Controller
{
    private function client(Request $request): IntegrationClient
    {
        return $request->attributes->get('integration_client');
    }

    public function accounts(Request $request): JsonResponse
    {
        $accounts = Account::query()
            ->whereIn('id', $this->client($request)->accountIds())
            ->orderBy('name')
            ->get(['id', 'name', 'city', 'province']);

        return response()->json(['data' => $accounts]);
    }

    public function consultations(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'account' => ['nullable', 'integer'],
            'updated_since' => ['nullable', 'date'],
            'include_deleted' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $allowed = $this->client($request)->accountIds();
        $query = Consultation::query()->withProductRelations();

        if ($request->boolean('include_deleted')) {
            $query->withTrashed();
        }

        // An account outside the allowlist yields an empty result, never another account's data.
        if (! empty($validated['account'])) {
            $query->where('account_id', in_array((int) $validated['account'], $allowed, true) ? (int) $validated['account'] : 0);
        } else {
            $query->whereIn('account_id', $allowed ?: [0]);
        }

        if (! empty($validated['updated_since'])) {
            $query->where('updated_at', '>=', Carbon::parse($validated['updated_since'])->utc());
        }

        if (! empty($validated['search'])) {
            $search = str_replace(['%', '_'], ['\\%', '\\_'], trim($validated['search']));
            $query->where(fn ($q) => $q->where('client_name', 'like', "%{$search}%")->orWhere('consultation_id', 'like', "%{$search}%"));
        }

        // Stable ascending order so consumers can page through and advance an updated_since cursor.
        $page = $query->orderBy('updated_at')->orderBy('id')->paginate((int) ($validated['per_page'] ?? 50));

        return response()->json([
            'data' => collect($page->items())->map(fn (Consultation $c) => $this->present($c))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function consultation(Request $request, string $consultationId): JsonResponse
    {
        $consultation = Consultation::query()
            ->withProductRelations()
            ->where('consultation_id', $consultationId)
            ->whereIn('account_id', $this->client($request)->accountIds() ?: [0])
            ->first();

        abort_if(! $consultation, 404, 'Data tidak ditemukan.');

        return response()->json(['data' => $this->present($consultation)]);
    }

    public function statuses(): JsonResponse
    {
        return response()->json(['data' => StatusCategory::query()->orderBy('sort_order')->get(['id', 'name', 'color'])]);
    }

    public function needsCategories(): JsonResponse
    {
        return response()->json(['data' => NeedsCategory::query()->orderBy('name')->get(['id', 'name'])]);
    }

    /** Explicit field list: internal columns (creator, audit fields, normalized emergency phone) are never exposed. */
    private function present(Consultation $c): array
    {
        $needs = collect();
        if ($c->relationLoaded('needsCategories')) {
            $needs = $c->needsCategories->pluck('name');
        }
        if ($needs->isEmpty() && $c->relationLoaded('needsCategory') && $c->needsCategory) {
            $needs = collect([$c->needsCategory->name]);
        }

        return [
            'consultation_id' => $c->consultation_id,
            'client_name' => $c->client_name,
            'phone' => $c->phone,
            'phone_normalized' => $c->phone_normalized,
            'emergency_phone' => $c->emergency_phone,
            'province' => $c->province,
            'city' => $c->city,
            'district' => $c->district,
            'address' => $c->address,
            'account_id' => $c->account_id,
            'account_name' => $c->account?->name,
            'needs' => $needs->values()->all(),
            'product_details' => $c->product_details,
            'status_id' => $c->status_category_id,
            'status_name' => $c->statusCategory?->name,
            'notes' => $c->notes,
            'consultation_date' => $c->consultation_date?->toDateString(),
            'created_at' => $c->created_at?->toIso8601ZuluString(),
            'updated_at' => $c->updated_at?->toIso8601ZuluString(),
            'deleted_at' => $c->deleted_at?->toIso8601ZuluString(),
        ];
    }
}
