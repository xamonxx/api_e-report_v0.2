<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Reminder extends Model
{
    use HasFactory, SoftDeletes;

    // F-015: Explicit $fillable instead of $guarded=['id'] to prevent accidental
    // mass-assignment of unintended columns if new columns are added to the table.
    protected $fillable = [
        'consultation_id',
        'user_id',
        'creator_id',
        'remind_at',
        'message',
        'is_read',
        'pushed_at',
    ];

    protected $casts = [
        'remind_at' => 'datetime',
        'is_read' => 'boolean',
        'pushed_at' => 'datetime',
    ];

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /**
     * Scope query berdasarkan hak akses user.
     * Admin hanya melihat pengingat miliknya (atau yang dibuatnya), SuperAdmin melihat semua.
     */
    public function scopeForUser($query, $user)
    {
        if ($user->isAdmin()) {
            $query->where(function ($q) use ($user) {
                $q->where('creator_id', $user->id)
                  ->orWhere('user_id', $user->id);
            });
        }

        return $query;
    }
}
