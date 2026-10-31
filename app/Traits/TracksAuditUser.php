<?php

namespace App\Traits;

/**
 * Trait TracksAuditUser
 *
 * Automatically populates created_by, updated_by, and deleted_by
 * columns with the currently authenticated user ID.
 *
 * Attach this trait to any Model that has these columns.
 */
trait TracksAuditUser
{
    protected static array $columnCache = [];

    protected static function hasAuditColumn($model, string $column): bool
    {
        // Nama koneksi aktual, bukan 'default': koneksi default bisa berganti
        // (mis. suite test SQLite fixture) dan cache tidak boleh terbawa.
        $key = $model->getConnection()->getName().':'.$model->getTable().':'.$column;

        if (! array_key_exists($key, static::$columnCache)) {
            static::$columnCache[$key] = $model->getConnection()->getSchemaBuilder()->hasColumn($model->getTable(), $column);
        }

        return static::$columnCache[$key];
    }

    public static function bootTracksAuditUser(): void
    {
        static::creating(function ($model) {
            if (
                auth()->check()
                && ! $model->isDirty('created_by')
                && static::hasAuditColumn($model, 'created_by')
            ) {
                $model->created_by = auth()->id();
            }
        });

        static::updating(function ($model) {
            if (auth()->check() && static::hasAuditColumn($model, 'updated_by')) {
                $model->updated_by = auth()->id();
            }
        });

        if (method_exists(static::class, 'bootSoftDeletes') || in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive(static::class))) {
            static::deleting(function ($model) {
                if (auth()->check() && static::hasAuditColumn($model, 'deleted_by')) {
                    $model->deleted_by = auth()->id();
                    $model->saveQuietly();
                }
            });
        }
    }

    /**
     * Relationship: user who created this record.
     */
    public function createdByUser()
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by')->withTrashed();
    }

    /**
     * Relationship: user who last updated this record.
     */
    public function updatedByUser()
    {
        return $this->belongsTo(\App\Models\User::class, 'updated_by')->withTrashed();
    }

    /**
     * Relationship: user who deleted this record.
     */
    public function deletedByUser()
    {
        return $this->belongsTo(\App\Models\User::class, 'deleted_by')->withTrashed();
    }
}
