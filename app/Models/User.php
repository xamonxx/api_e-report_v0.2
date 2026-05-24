<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Traits\TracksAuditUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes, TracksAuditUser;

    /**
     * Whitelist: Only these fields can be mass-assigned.
     * SECURITY: 'role' is intentionally included because it's always set
     * explicitly from validated + enum-checked data in controllers,
     * never from raw user input.
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'account_id',
        'primary_color',
        'last_login_at',
        'last_login_ip',
        'last_seen_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'role' => UserRole::class,
        ];
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function consultations()
    {
        return $this->hasMany(Consultation::class, 'created_by');
    }

    public function reportAttendances()
    {
        return $this->hasMany(ReportAttendance::class);
    }

    public function consultationNotes()
    {
        return $this->hasMany(ConsultationNote::class);
    }

    public function loginAttempts()
    {
        return $this->hasMany(LoginAttempt::class, 'email', 'email');
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === UserRole::SuperAdmin;
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }
}
