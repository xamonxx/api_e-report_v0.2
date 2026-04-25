<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class StatusCategory extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['name', 'color', 'css_class', 'sort_order'];

    protected $appends = ['chip_style'];

    public function consultations()
    {
        return $this->hasMany(Consultation::class);
    }

    public function getChipStyleAttribute(): string
    {
        $hex = $this->normalizeHex($this->color ?: '#737c7f');
        $rgb = $this->hexToRgb($hex);

        return sprintf(
            'background-color: rgba(%d, %d, %d, 0.14); color: %s;',
            $rgb['r'],
            $rgb['g'],
            $rgb['b'],
            $hex
        );
    }

    private function normalizeHex(string $hex): string
    {
        $hex = ltrim(trim($hex), '#');

        if (strlen($hex) === 3) {
            $hex = preg_replace('/(.)/', '$1$1', $hex);
        }

        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return '#737c7f';
        }

        return '#' . strtoupper($hex);
    }

    private function hexToRgb(string $hex): array
    {
        $value = ltrim($hex, '#');

        return [
            'r' => hexdec(substr($value, 0, 2)),
            'g' => hexdec(substr($value, 2, 2)),
            'b' => hexdec(substr($value, 4, 2)),
        ];
    }
}
