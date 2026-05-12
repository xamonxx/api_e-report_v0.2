<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class Consultation extends Model
{
    use HasFactory, SoftDeletes, Auditable;

    protected $fillable = [
        'consultation_id',
        'client_name',
        'phone',
        'province',
        'city',
        'district',
        'address',
        'account_id',
        'needs_category_id',
        'product_details',
        'status_category_id',
        'notes',
        'created_by',
        'consultation_date',
    ];

    protected function casts(): array
    {
        return [
            'consultation_date' => 'date',
        ];
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function needsCategory()
    {
        return $this->belongsTo(NeedsCategory::class);
    }

    public function needsCategories()
    {
        return $this->belongsToMany(NeedsCategory::class)
            ->withTimestamps();
    }

    public static function hasNeedsCategoryPivot(): bool
    {
        return Cache::remember('schema:consultation_needs_category_exists', now()->addHour(), function () {
            return Schema::hasTable('consultation_needs_category');
        });
    }

    public static function productRelations(): array
    {
        return static::hasNeedsCategoryPivot()
            ? ['needsCategory', 'needsCategories']
            : ['needsCategory'];
    }

    public function scopeWithProductRelations($query)
    {
        return $query->with(array_merge(['account'], static::productRelations(), ['statusCategory', 'creator']));
    }

    public function statusCategory()
    {
        return $this->belongsTo(StatusCategory::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function timelineNotes()
    {
        return $this->hasMany(ConsultationNote::class, 'consultation_id')->latest();
    }

    public function reminders()
    {
        return $this->hasMany(Reminder::class)->latest();
    }

    public function productCategories(): Collection
    {
        if ($this->relationLoaded('needsCategories') && $this->needsCategories->isNotEmpty()) {
            return $this->needsCategories;
        }

        if ($this->relationLoaded('needsCategory') && $this->needsCategory) {
            return collect([$this->needsCategory]);
        }

        if (!static::hasNeedsCategoryPivot()) {
            return $this->needsCategory ? collect([$this->needsCategory]) : collect();
        }

        $categories = $this->needsCategories()->get();

        if ($categories->isNotEmpty()) {
            return $categories;
        }

        return $this->needsCategory ? collect([$this->needsCategory]) : collect();
    }

    public function getProductNamesLabelAttribute(): string
    {
        return $this->productCategories()
            ->pluck('name')
            ->filter()
            ->unique()
            ->implode(', ');
    }

    public static function normalizeLeadText(?string $value): string
    {
        return Str::of((string) $value)
            ->replaceMatches('/\s+/u', ' ')
            ->trim()
            ->lower()
            ->toString();
    }

    public static function normalizeLeadPhone(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value) ?? '';
    }

    public static function normalizeLeadCategoryIds(mixed $value): array
    {
        return collect(is_array($value) || $value instanceof Collection ? $value : [$value])
            ->filter(fn ($item) => filled($item))
            ->map(fn ($item) => (int) $item)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    public static function buildLeadPhoneKey(int|string|null $accountId, ?string $phone): string
    {
        return (int) ($accountId ?? 0) . '|' . static::normalizeLeadPhone($phone);
    }

    public static function buildLeadProfileKey(array $attributes): string
    {
        $signature = [
            'account_id' => (int) ($attributes['account_id'] ?? 0),
            'client_name' => static::normalizeLeadText($attributes['client_name'] ?? null),
            'province' => static::normalizeLeadText($attributes['province'] ?? null),
            'city' => static::normalizeLeadText($attributes['city'] ?? null),
            'district' => static::normalizeLeadText($attributes['district'] ?? null),
            'address' => static::normalizeLeadText($attributes['address'] ?? null),
            'product_details' => static::normalizeLeadText($attributes['product_details'] ?? null),
            'needs_category_ids' => implode(',', static::normalizeLeadCategoryIds($attributes['needs_category_ids'] ?? [])),
        ];

        return implode('|', $signature);
    }

    public function buildLeadPhoneKeyFromModel(): string
    {
        return static::buildLeadPhoneKey($this->account_id, $this->phone);
    }

    public function buildLeadProfileKeyFromModel(): string
    {
        return static::buildLeadProfileKey([
            'account_id' => $this->account_id,
            'client_name' => $this->client_name,
            'province' => $this->province,
            'city' => $this->city,
            'district' => $this->district,
            'address' => $this->address,
            'product_details' => $this->product_details,
            'needs_category_ids' => $this->productCategories()->pluck('id')->all(),
        ]);
    }

    public function matchesLeadData(array $attributes): bool
    {
        $accountId = (int) ($attributes['account_id'] ?? 0);

        if ($accountId <= 0 || $accountId !== (int) $this->account_id) {
            return false;
        }

        $incomingPhoneKey = static::buildLeadPhoneKey($accountId, $attributes['phone'] ?? null);

        if ($incomingPhoneKey === $this->buildLeadPhoneKeyFromModel()) {
            return true;
        }

        return static::buildLeadProfileKey($attributes) === $this->buildLeadProfileKeyFromModel();
    }

    public static function findDuplicateLead(array $attributes, ?int $ignoreId = null): ?self
    {
        $accountId = (int) ($attributes['account_id'] ?? 0);

        if ($accountId <= 0) {
            return null;
        }

        $baseQuery = static::query()
            ->withTrashed()
            ->where('account_id', $accountId);

        if ($ignoreId) {
            $baseQuery->whereKeyNot($ignoreId);
        }

        $phoneDuplicateQuery = clone $baseQuery;
        static::applyNormalizedPhoneConstraint($phoneDuplicateQuery, $attributes['phone'] ?? null);
        $phoneDuplicate = $phoneDuplicateQuery->first();

        if ($phoneDuplicate) {
            return $phoneDuplicate;
        }

        $profileDuplicateQuery = clone $baseQuery;
        static::applyNormalizedTextConstraint($profileDuplicateQuery, 'client_name', $attributes['client_name'] ?? null);
        static::applyNormalizedTextConstraint($profileDuplicateQuery, 'province', $attributes['province'] ?? null, true);
        static::applyNormalizedTextConstraint($profileDuplicateQuery, 'city', $attributes['city'] ?? null, true);
        static::applyNormalizedTextConstraint($profileDuplicateQuery, 'district', $attributes['district'] ?? null, true);
        static::applyNormalizedTextConstraint($profileDuplicateQuery, 'address', $attributes['address'] ?? null, true);
        static::applyNormalizedTextConstraint($profileDuplicateQuery, 'product_details', $attributes['product_details'] ?? null, true);
        static::applyCategoryMatchConstraint($profileDuplicateQuery, $attributes['needs_category_ids'] ?? []);

        return $profileDuplicateQuery->first();
    }

    public static function findDuplicatePhone(int|string|null $accountId, ?string $phone, ?int $ignoreId = null): ?self
    {
        $accountId = (int) ($accountId ?? 0);
        $phoneKey = static::buildLeadPhoneKey($accountId, $phone);

        if ($accountId <= 0 || $phoneKey === '0|') {
            return null;
        }

        $query = static::query()
            ->withTrashed()
            ->where('account_id', $accountId);

        if ($ignoreId) {
            $query->whereKeyNot($ignoreId);
        }

        static::applyNormalizedPhoneConstraint($query, $phone);

        return $query->first();
    }

    protected static function applyNormalizedTextConstraint(
        Builder $query,
        string $column,
        ?string $value,
        bool $nullable = false
    ): void {
        $normalized = static::normalizeLeadText($value);

        if ($nullable && $normalized === '') {
            $query->where(function (Builder $innerQuery) use ($column) {
                $innerQuery->whereNull($column)
                    ->orWhereRaw("TRIM(COALESCE({$column}, '')) = ''");
            });

            return;
        }

        $query->whereRaw("LOWER(TRIM(COALESCE({$column}, ''))) = ?", [$normalized]);
    }

    protected static function applyNormalizedPhoneConstraint(Builder $query, ?string $phone): void
    {
        $normalizedPhone = static::normalizeLeadPhone($phone);
        $expression = static::normalizedPhoneSqlExpression('phone');

        if ($normalizedPhone === '') {
            $query->where(function (Builder $innerQuery) use ($expression) {
                $innerQuery->whereNull('phone')
                    ->orWhereRaw("{$expression} = ''");
            });

            return;
        }

        $query->whereRaw("{$expression} = ?", [$normalizedPhone]);
    }

    protected static function normalizedPhoneSqlExpression(string $column): string
    {
        return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE({$column}, ''), ' ', ''), '-', ''), '+', ''), '(', ''), ')', '')";
    }

    protected static function applyCategoryMatchConstraint(Builder $query, mixed $categoryIds): void
    {
        $normalizedIds = static::normalizeLeadCategoryIds($categoryIds);
        $idsCount = count($normalizedIds);

        if (!static::hasNeedsCategoryPivot()) {
            if ($idsCount === 0) {
                $query->where(function (Builder $innerQuery) {
                    $innerQuery->whereNull('needs_category_id')
                        ->orWhere('needs_category_id', 0);
                });

                return;
            }

            if ($idsCount === 1) {
                $query->where('needs_category_id', $normalizedIds[0]);

                return;
            }

            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $categoryQuery) use ($normalizedIds, $idsCount) {
            if ($idsCount > 0) {
                $categoryQuery->where(function (Builder $pivotQuery) use ($normalizedIds, $idsCount) {
                    $pivotQuery
                        ->whereHas(
                            'needsCategories',
                            fn (Builder $relationQuery) => $relationQuery->whereIn('needs_categories.id', $normalizedIds),
                            '=',
                            $idsCount
                        )
                        ->whereDoesntHave(
                            'needsCategories',
                            fn (Builder $relationQuery) => $relationQuery->whereNotIn('needs_categories.id', $normalizedIds)
                        );
                });

                if ($idsCount === 1) {
                    $categoryQuery->orWhere(function (Builder $legacyQuery) use ($normalizedIds) {
                        $legacyQuery
                            ->whereDoesntHave('needsCategories')
                            ->where('needs_category_id', $normalizedIds[0]);
                    });
                }

                return;
            }

            $categoryQuery->where(function (Builder $noCategoryQuery) {
                $noCategoryQuery
                    ->whereDoesntHave('needsCategories')
                    ->where(function (Builder $legacyFallbackQuery) {
                        $legacyFallbackQuery->whereNull('needs_category_id')
                            ->orWhere('needs_category_id', 0);
                    });
            });
        });
    }

    // ── Query Scopes ─────────────────────────────────

    /**
     * Scope query berdasarkan hak akses user.
     * Admin hanya melihat data milik akunnya, SuperAdmin melihat semua.
     */
    public function scopeForUser($query, $user)
    {
        if ($user->isAdmin()) {
            $query->where($this->qualifyColumn('account_id'), $user->account_id);
        }

        return $query;
    }

    /**
     * Auto-generate consultation ID in format: AA.YYMM.NNNN
     * - AA   = ID Akun (zero-padded 2 digit)
     * - YYMM = Tahun 2 digit + Bulan 2 digit
     * - NNNN = Nomor urut per akun per bulan (4 digit)
     * Contoh: 01.2604.0001
     *
     * Menggunakan sequence row per akun per bulan agar increment
     * tetap atomik saat banyak request berjalan bersamaan.
     * Optimized: reduce lock time và handle high concurrency better.
     */
    public static function generateConsultationId($accountId = null): string
    {
        $maxRetries = 3;
        $retryCount = 0;
        $normalizedAccountId = (int) ($accountId ?? 0);
        $accountPadded = str_pad((string) $normalizedAccountId, 2, '0', STR_PAD_LEFT);
        $yearMonth = Carbon::now()->format('ym');

        while ($retryCount < $maxRetries) {
            try {
                return DB::transaction(function () use ($normalizedAccountId, $accountPadded, $yearMonth) {
                    $now = Carbon::now();

                    $sequence = DB::table('consultation_sequences')
                        ->where('account_id', $normalizedAccountId)
                        ->where('year_month', $yearMonth)
                        ->lockForUpdate()
                        ->first();

                    if (!$sequence) {
                        DB::table('consultation_sequences')->insertOrIgnore([
                            'account_id' => $normalizedAccountId,
                            'year_month' => $yearMonth,
                            'last_number' => 0,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);

                        $sequence = DB::table('consultation_sequences')
                            ->where('account_id', $normalizedAccountId)
                            ->where('year_month', $yearMonth)
                            ->lockForUpdate()
                            ->first();
                    }

                    if (!$sequence) {
                        return $accountPadded . '.' . $yearMonth . '.0001';
                    }

                    $nextNum = ((int) $sequence->last_number) + 1;

                    DB::table('consultation_sequences')
                        ->where('id', $sequence->id)
                        ->update([
                            'last_number' => $nextNum,
                            'updated_at' => $now,
                        ]);

                    return $accountPadded . '.' . $yearMonth . '.' . str_pad((string) $nextNum, 4, '0', STR_PAD_LEFT);
                }, 5);
            } catch (\Illuminate\Database\QueryException $e) {
                $retryCount++;
                if ($retryCount >= $maxRetries) {
                    break;
                }
                usleep(50000 * $retryCount);
            }
        }

        return $accountPadded . '.' . $yearMonth . '.' . str_pad((string) rand(1, 9999), 4, '0', STR_PAD_LEFT);
    }
}
