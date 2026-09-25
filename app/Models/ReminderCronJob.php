<?php

namespace App\Models;

use App\Traits\TracksAuditUser;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class ReminderCronJob extends Model
{
    use TracksAuditUser;

    public const TYPE_ATTENDANCE_REMINDER = 'attendance_reminder';

    protected $fillable = [
        'name',
        'type',
        'time_of_day',
        'message',
        'is_active',
        'last_sent_date',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_sent_date' => 'date',
        ];
    }

    /** Simpan sebagai SQL TIME, tetapi pertahankan kontrak API dalam format HH:mm. */
    protected function timeOfDay(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value === null ? null : substr($value, 0, 5),
            set: fn (?string $value) => $value === null ? null : substr($value, 0, 5).':00',
        );
    }
}
