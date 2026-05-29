<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConsultationStatusHistory extends Model
{
    protected $fillable = [
        'consultation_id',
        'account_id',
        'from_status_id',
        'to_status_id',
        'changed_by',
    ];

    protected $casts = [
        'consultation_id' => 'integer',
        'account_id' => 'integer',
        'from_status_id' => 'integer',
        'to_status_id' => 'integer',
        'changed_by' => 'integer',
    ];

    public function consultation()
    {
        return $this->belongsTo(Consultation::class);
    }

    public function fromStatus()
    {
        return $this->belongsTo(StatusCategory::class, 'from_status_id');
    }

    public function toStatus()
    {
        return $this->belongsTo(StatusCategory::class, 'to_status_id');
    }

    /**
     * Catat satu transisi status lead. Lewati jika status tidak berubah.
     */
    public static function record(Consultation $consultation, ?int $fromStatusId, ?int $toStatusId): ?self
    {
        if ((int) $fromStatusId === (int) $toStatusId) {
            return null;
        }

        return static::create([
            'consultation_id' => $consultation->id,
            'account_id' => $consultation->account_id,
            'from_status_id' => $fromStatusId,
            'to_status_id' => $toStatusId,
            'changed_by' => auth()->id(),
        ]);
    }
}
