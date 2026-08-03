<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceNotification extends Model
{
    protected $fillable = [
        'user_id',
        'report_attendance_id',
        'title',
        'message',
        'admin_name',
        'account_name',
        'report_date',
        'report_category',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'report_date' => 'date',
            'read_at' => 'datetime',
        ];
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(ReportAttendance::class, 'report_attendance_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
