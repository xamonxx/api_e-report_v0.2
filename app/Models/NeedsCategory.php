<?php

namespace App\Models;

use App\Support\PendingConfirmation;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class NeedsCategory extends Model
{
    use HasFactory, SoftDeletes;

    public const OTHER_OPTION_LABEL = 'Lain-lain';

    public const DISPLAY_NAMES = [
        PendingConfirmation::LABEL,
        self::OTHER_OPTION_LABEL,
        'Kitchenset',
        'Wall Moulding',
        'Backdrop TV',
        'Full Home',
        'Kamarset',
        'Apartement',
        'Wall Panel',
        'Almunium',
        'Dipan',
        'Lemari',
        'Living Room',
        'Partisi',
        'Semi Full Home',
        'Sipil',
        'Bench',
        'Box',
        'Cabinet',
        'Cabinet Laundry',
        'Cafe Resto',
        'Cermin',
        'Cradienza',
        'Jasa Design',
        'Kaca',
        'Kantor',
        'Lemari Bawah Tangga',
        'Meja',
        'Meja Kerja',
        'Meja Rias',
        'Mini Bar',
        'Nakas',
        'Pintu',
        'Rak',
        'Renovasi Rumah',
        'Toko',
        'Walkin Closet',
        'Wardrobe',
    ];

    protected $fillable = ['name'];

    public static function displayNames(): array
    {
        return self::DISPLAY_NAMES;
    }

    public function scopeForConsultationOptions($query)
    {
        $names = self::displayNames();
        $placeholders = implode(', ', array_fill(0, count($names), '?'));

        return $query
            ->whereIn('name', $names)
            ->orderByRaw("FIELD(name, {$placeholders}) = 0, FIELD(name, {$placeholders})", array_merge($names, $names));
    }

    public function consultations()
    {
        if (Consultation::hasNeedsCategoryPivot()) {
            return $this->belongsToMany(Consultation::class)
                ->withTimestamps();
        }

        return $this->hasMany(Consultation::class);
    }

    public function primaryConsultations()
    {
        return $this->hasMany(Consultation::class);
    }
}
