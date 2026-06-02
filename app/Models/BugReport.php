<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BugReport extends Model
{
    protected $fillable = [
        'ticket_code',
        'description',
        'page_url',
        'reporter_email',
        'image_paths',
        'status',
        'user_id',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'image_paths' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Generate a short, human-friendly, collision-checked ticket code.
     * e.g. "BUG-7K3F9Q2M"
     */
    public static function generateTicketCode(): string
    {
        do {
            $code = 'BUG-' . Str::upper(Str::random(8));
        } while (static::where('ticket_code', $code)->exists());

        return $code;
    }

    /**
     * Public URLs for the stored screenshots (for admin viewing).
     *
     * @return array<int, string>
     */
    public function imageUrls(): array
    {
        return collect($this->image_paths ?? [])
            ->map(fn (string $path) => Storage::disk('public')->url($path))
            ->all();
    }
}
