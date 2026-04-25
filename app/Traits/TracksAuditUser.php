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
    public static function bootTracksAuditUser(): void
    {
        static::creating(function ($model) {
            if (!$model->isDirty('created_by') && auth()->check()) {
                if ($model->isFillable('created_by') || in_array('created_by', $model->getGuarded() === ['*'] ? [] : $model->getFillable())) {
                    $model->created_by = auth()->id();
                }
            }
        });

        static::updating(function ($model) {
            if (auth()->check() && $model->getConnection()->getSchemaBuilder()->hasColumn($model->getTable(), 'updated_by')) {
                $model->updated_by = auth()->id();
            }
        });

        if (method_exists(static::class, 'bootSoftDeletes') || in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive(static::class))) {
            static::deleting(function ($model) {
                if (auth()->check() && $model->getConnection()->getSchemaBuilder()->hasColumn($model->getTable(), 'deleted_by')) {
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
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    /**
     * Relationship: user who last updated this record.
     */
    public function updatedByUser()
    {
        return $this->belongsTo(\App\Models\User::class, 'updated_by');
    }

    /**
     * Relationship: user who deleted this record.
     */
    public function deletedByUser()
    {
        return $this->belongsTo(\App\Models\User::class, 'deleted_by');
    }
}
