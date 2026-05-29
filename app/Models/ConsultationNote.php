<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ConsultationNote extends Model
{
    use HasFactory, SoftDeletes;

    // F-015: Explicit $fillable instead of $guarded=['id'] to prevent accidental
    // mass-assignment of unintended columns if new columns are added to the table.
    protected $fillable = [
        'consultation_id',
        'user_id',
        'body',
        'is_read',
    ];

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
