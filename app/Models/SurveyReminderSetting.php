<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Satu baris global (key selalu DEFAULT_KEY). Bukan tabel key-value generik
 * karena rilis pertama ini cuma butuh satu aturan pengingat (lihat K2 di
 * Decision-Log - "satu aturan global" dipilih sebagai default aman).
 */
class SurveyReminderSetting extends Model
{
    use Auditable;

    public const DEFAULT_KEY = 'default';

    protected $fillable = [
        'key',
        'enabled',
        'lead_minutes',
        'message_template',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'lead_minutes' => 'integer',
        ];
    }

    /** Baris global tunggal, dibuat otomatis (nonaktif, 300 menit) kalau belum ada. */
    public static function current(): self
    {
        return static::firstOrCreate(
            ['key' => self::DEFAULT_KEY],
            ['enabled' => false, 'lead_minutes' => 300]
        );
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by')->withTrashed();
    }
}
