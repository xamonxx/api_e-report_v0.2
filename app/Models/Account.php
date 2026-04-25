<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Traits\TracksAuditUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    use HasFactory, SoftDeletes, TracksAuditUser;

    protected $fillable = [
        'name',
        'logo_path',
        'description',
        'city',
        'province',
        'target_leads',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function admins(): HasMany
    {
        return $this->hasMany(User::class)->where('role', UserRole::Admin);
    }

    public function consultations()
    {
        return $this->hasMany(Consultation::class);
    }

    public function getLeadCountAttribute(): int
    {
        // Gunakan nilai eager load jika tersedia, cegah N+1
        return $this->consultations_count ?? $this->consultations()->count();
    }

    public function getConversionRateAttribute(): float
    {
        // Gunakan eager loaded counts untuk cegah N+1
        $total = $this->consultations_count ?? $this->consultations()->count();
        if ($total === 0) return 0;

        if (isset($this->deals_count)) {
            $deals = $this->deals_count;
        } else {
            // Fallback: resolve status ID directly (no JOIN)
            static $dealStatusId;
            if (!isset($dealStatusId)) {
                $dealStatusId = StatusCategory::whereIn('name', array_filter([
                    config('statuses.deal'),
                    'Selesai/Deal',
                    'Selesai Deal',
                ]))->value('id');
            }
            $deals = $dealStatusId
                ? $this->consultations()->where('status_category_id', $dealStatusId)->count()
                : 0;
        }

        return round(($deals / $total) * 100, 1);
    }

    public function getTargetProgressAttribute(): float
    {
        if ($this->target_leads <= 0) return 0;
        return round(($this->lead_count / $this->target_leads) * 100, 1);
    }
}
