<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class IntegrationClient extends Model
{
    public const ABILITY_CONSULTATIONS_READ = 'consultations:read';

    protected $fillable = ['name', 'token_hash', 'token_prefix', 'allowed_account_ids', 'abilities', 'is_active', 'expires_at', 'created_by'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'allowed_account_ids' => 'array',
            'abilities' => 'array',
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Create a client and return the plain token once. Only its SHA-256 hash is stored.
     *
     * @param  array<int>  $accountIds
     * @return array{0: self, 1: string}
     */
    public static function issue(string $name, array $accountIds, ?\DateTimeInterface $expiresAt = null, ?int $createdBy = null): array
    {
        $plain = 'erpi_'.Str::random(48);
        $client = static::create([
            'name' => $name,
            'token_hash' => hash('sha256', $plain),
            'token_prefix' => substr($plain, 0, 12),
            'allowed_account_ids' => array_values(array_unique(array_map('intval', $accountIds))),
            'abilities' => [self::ABILITY_CONSULTATIONS_READ],
            'is_active' => true,
            'expires_at' => $expiresAt,
            'created_by' => $createdBy,
        ]);

        return [$client, $plain];
    }

    /** @return array<int> */
    public function accountIds(): array
    {
        return array_map('intval', $this->allowed_account_ids ?? []);
    }

    public function can(string $ability): bool
    {
        return in_array($ability, $this->abilities ?? [], true);
    }

    public function isUsable(): bool
    {
        return $this->is_active && (! $this->expires_at || $this->expires_at->isFuture());
    }
}
