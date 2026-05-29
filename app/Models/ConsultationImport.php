<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsultationImport extends Model
{
    // F-015: Explicit $fillable instead of $guarded=['id'] to prevent accidental
    // mass-assignment of unintended columns if new columns are added to the table.
    protected $fillable = [
        'user_id',
        'original_name',
        'stored_path',
        'status',
        'total_rows',
        'success_count',
        'updated_count',
        'duplicate_count',
        'error_count',
        'error_preview',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
