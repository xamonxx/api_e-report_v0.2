<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DashboardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'stats' => $this->resource['stats'] ?? [],
            'recent_consultations' => $this->resource['recent_consultations'] ?? [],
            'upcoming' => $this->resource['upcoming'] ?? [],
            'status_distribution' => $this->formatDistribution($this->resource['status_distribution'] ?? []),
            'needs_distribution' => $this->formatDistribution($this->resource['needs_distribution'] ?? []),
            'accounts' => $this->resource['accounts'] ?? [],
            'admin_attendances' => $this->resource['admin_attendances'] ?? [],
            'top_admin' => $this->resource['top_admin'] ?? null,
            'account' => $this->resource['account'] ?? null,
        ];
    }

    private function formatDistribution($items): array
    {
        return collect($items)->map(fn ($item) => [
            'id' => $item->id ?? null,
            'name' => $item->name ?? '',
            'count' => $item->consultations_count ?? 0,
            'css_class' => $item->css_class ?? null,
        ])->toArray();
    }
}