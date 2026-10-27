<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $fillable = [
        'loggable_type',
        'loggable_id',
        'action',
        'user_id',
        'user_name',
        'description',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'loggable_id' => 'integer',
        'user_id' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function loggable()
    {
        return $this->morphTo();
    }

    // ── Logging Helpers ─────────────────────────────────

    public static function logCreated($model, ?string $description = null)
    {
        return static::recordLog($model, 'created', $description ?? 'Membuat ' . class_basename($model) . ' baru', null, $model->toArray());
    }

    public static function logUpdated($model, array $oldValues, ?string $description = null)
    {
        return static::recordLog($model, 'updated', $description ?? 'Memperbarui ' . class_basename($model), $oldValues, $model->toArray());
    }

    public static function logDeleted($model, ?string $description = null)
    {
        return static::recordLog($model, 'deleted', $description ?? 'Menghapus ' . class_basename($model), $model->toArray(), null);
    }

    public static function logRetrieved($model, ?string $description = null)
    {
        return static::recordLog($model, 'retrieved', $description ?? 'Melihat ' . class_basename($model), null, null);
    }

    /**
     * Metode terpusat untuk mencatat semua jenis audit log.
     */
    private static function recordLog($model, string $action, string $description, ?array $oldValues, ?array $newValues)
    {
        $user = auth()->user();

        return static::create([
            'loggable_type' => get_class($model),
            'loggable_id' => $model->id,
            'action' => $action,
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'description' => $description,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
