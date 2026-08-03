<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Traits\TracksAuditUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    public const SUPER_ADMIN_CACHE_KEY = 'users:super-admin-ids';

    use HasApiTokens, HasFactory, Notifiable, SoftDeletes, TracksAuditUser;

    /**
     * Whitelist: Only these fields can be mass-assigned.
     * F-014: 'role' is intentionally NOT in $fillable to prevent privilege escalation
     * via mass-assignment. Role must be assigned explicitly: $user->role = UserRole::X;
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'account_id',
        'primary_color',
        'avatar_path',
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

    public function isSurveyor(): bool
    {
        return $this->role === UserRole::Surveyor;
    }

    public function isManagerSurveyor(): bool
    {
        return $this->role === UserRole::ManagerSurveyor;
    }

    public function isSurveyTeam(): bool
    {
        return $this->isSurveyor() || $this->isManagerSurveyor();
    }

    /**
     * Dashboard invalidation runs on every operational mutation. Cache this
     * stable list so completing a survey does not query users again.
     *
     * @return list<int>
     */
    public static function cachedSuperAdminIds(): array
    {
        return Cache::remember(
            self::SUPER_ADMIN_CACHE_KEY,
            now()->addHour(),
            fn () => self::query()
                ->where('role', UserRole::SuperAdmin->value)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all()
        );
    }

    public function assignedSurveys()
    {
        return $this->hasMany(Survey::class, 'surveyor_id');
    }
}
