<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Izin eksplisit Super Admin untuk menugaskan surveyor lintas tim di luar
 * Team F (yang sudah diizinkan meminjam tanpa persetujuan tambahan - lihat
 * SurveyController::BORROWABLE_TEAM). Satu baris = satu izin untuk satu
 * pasangan (survey, surveyor); dicabut lewat revoked_at/revoked_by, bukan
 * dihapus, supaya jejak siapa menyetujui apa dan kapan tetap utuh.
 */
class SurveyLoanApproval extends Model
{
    use Auditable;

    protected $fillable = [
        'survey_id',
        'surveyor_id',
        'borrower_team',
        'lender_team',
        'approved_by',
        'approved_at',
        'reason',
        'revoked_by',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function survey()
    {
        return $this->belongsTo(Survey::class);
    }

    public function surveyor()
    {
        return $this->belongsTo(User::class, 'surveyor_id')->withTrashed();
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by')->withTrashed();
    }

    public function revoker()
    {
        return $this->belongsTo(User::class, 'revoked_by')->withTrashed();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }
}
