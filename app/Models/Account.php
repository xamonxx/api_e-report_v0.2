<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Traits\TracksAuditUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

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
        'account_group',
        'account_group_id',
    ];

    protected static function booted(): void
    {
        static::saving(function (Account $account) {
            try {
                if (
                    $account->account_group_id
                    && (! $account->account_group || ($account->isDirty('account_group_id') && ! $account->isDirty('account_group')))
                ) {
                    if (Schema::hasTable('account_groups')) {
                        $account->account_group = AccountGroup::whereKey($account->account_group_id)->value('code') ?? $account->account_group;
                    }
                } elseif ($account->account_group && ! $account->account_group_id) {
                    $code = mb_strtoupper(trim((string) $account->account_group));
                    if ($code !== '' && Schema::hasTable('account_groups')) {
                        $account->account_group_id = AccountGroup::where('code', $code)->value('id');
                    }
                }
            } catch (QueryException) {
                // Abaikan bila tabel account_groups belum tersedia di fixture test lama.
            }
        });
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function admins(): HasMany
    {
        return $this->hasMany(User::class)->where('role', UserRole::Admin);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function consultations()
    {
        return $this->hasMany(Consultation::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(AccountGroup::class, 'account_group_id');
    }

    public function currentGroupConsultations(): HasMany
    {
        return $this->hasMany(Consultation::class)
            ->where(function ($query) {
                $query->whereIn('consultations.account_group_id', function ($sub) {
                    $sub->select('a.account_group_id')
                        ->from('accounts as a')
                        ->whereColumn('a.id', 'consultations.account_id');
                })->orWhere(function ($fallback) {
                    $fallback->whereNull('consultations.account_group_id')
                        ->whereIn('consultations.account_group', function ($sub) {
                            $sub->select('a.account_group')
                                ->from('accounts as a')
                                ->whereColumn('a.id', 'consultations.account_id');
                        });
                });
            });
    }

    public function getLeadCountAttribute(): int
    {
        // Gunakan nilai eager load jika tersedia, cegah N+1
        return $this->consultations_count ?? $this->currentGroupConsultations()->count();
    }

    public function getConversionRateAttribute(): float
    {
        // Gunakan eager loaded counts untuk cegah N+1
        $total = $this->consultations_count ?? $this->currentGroupConsultations()->count();
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
                ? $this->currentGroupConsultations()->where('status_category_id', $dealStatusId)->count()
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
