<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoginAttempt extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'email',
        'ip_address',
        'user_agent',
        'successful',
        'attempted_at',
    ];

    protected function casts(): array
    {
        return [
            'successful' => 'boolean',
            'attempted_at' => 'datetime',
        ];
    }

    /**
     * Record a login attempt.
     */
    public static function record(string $email, string $ip, ?string $userAgent, bool $successful): self
    {
        return static::create([
            'email' => mb_strtolower(trim($email)),
            'ip_address' => $ip,
            'user_agent' => $userAgent ? mb_substr($userAgent, 0, 255) : null,
            'successful' => $successful,
            'attempted_at' => now(),
        ]);
    }

    /**
     * Count failed login attempts within a time window.
     */
    public static function recentFailedCount(string $email, string $ip, int $minutes = 15): int
    {
        return static::where('email', mb_strtolower(trim($email)))
            ->where('ip_address', $ip)
            ->where('successful', false)
            ->where('attempted_at', '>=', now()->subMinutes($minutes))
            ->count();
    }

    /**
     * F-012: Count failed attempts by email only (any IP).
     * Defends against distributed brute-force / credential stuffing attacks
     * where the attacker rotates IP addresses to bypass per-IP checks.
     */
    public static function recentFailedCountByEmail(string $email, int $minutes = 60): int
    {
        return static::where('email', mb_strtolower(trim($email)))
            ->where('successful', false)
            ->where('attempted_at', '>=', now()->subMinutes($minutes))
            ->count();
    }

    /**
     * Check if too many failed attempts (brute force protection — per email+IP).
     */
    public static function isTooManyAttempts(string $email, string $ip, int $maxAttempts = 5, int $minutes = 15): bool
    {
        return static::recentFailedCount($email, $ip, $minutes) >= $maxAttempts;
    }

    /**
     * F-012: Check if too many failed attempts by email alone (any IP).
     * Higher threshold to allow legitimate users on dynamic IPs while
     * blocking credential stuffing across distributed attack sources.
     */
    public static function isTooManyAttemptsByEmail(string $email, int $maxAttempts = 20, int $minutes = 60): bool
    {
        return static::recentFailedCountByEmail($email, $minutes) >= $maxAttempts;
    }

    /**
     * Purge old login attempts (cleanup, run via scheduled command).
     */
    public static function purgeOlderThan(int $days = 30): int
    {
        return static::where('attempted_at', '<', now()->subDays($days))->delete();
    }
}
