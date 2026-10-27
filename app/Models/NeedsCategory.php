<?php

namespace App\Models;

use App\Support\PendingConfirmation;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class NeedsCategory extends Model
{
    use HasFactory, SoftDeletes;

    public const OTHER_OPTION_LABEL = 'Lain-Lain';

    public const OTHER_OPTION_LEGACY_LABEL = 'Lain-lain';

    /**
     * Kategori default ketika lead disimpan tanpa memilih kebutuhan apa pun.
     * Dulu memakai PendingConfirmation::LABEL yang sama dengan placeholder
     * wilayah; kini keduanya dipisah agar bisa diubah sendiri-sendiri.
     */
    public const PENDING_LABEL = 'Tidak konfirmasi';

    /**
     * Ejaan lama yang masih mungkin muncul di file import milik tim.
     */
    public const PENDING_LEGACY_LABEL = PendingConfirmation::LEGACY_LABEL;

    /**
     * Urutan tampil kategori bawaan (58 item resmi). Ini menentukan urutan kurasi
     * pada form/dropdown dan menjadi sumber seeder.
     */
    public const DISPLAY_NAMES = [
        'Alumunium',
        'Backdrop TV',
        'Bench',
        'Box Elektronik',
        'Cabinet',
        'Cabinet Laundry',
        'Cermin',
        'Cradienza',
        'Dipan',
        'Full Home',
        'Headboard',
        'Interior Apartemen',
        'Interior Cafe',
        'Interior Hotel',
        'Interior Kantor',
        'Interior Penginapan',
        'Interior Resort',
        'Interior Restaurant',
        'Interior Rumah Makan',
        'Interior Rumah Sakit',
        'Interior Toko',
        'Interior Vila',
        'Jasa Desain',
        'Kaca',
        'Kamarset',
        'Kitchenset',
        'Kitchenset Alumunium',
        'Lemari',
        'Lemari Alumunium',
        'Lemari Bawah Tangga',
        'Living Room',
        'Meja',
        'Meja Island',
        'Meja Kerja',
        'Meja Rias',
        'Mini Bar',
        'Nakas',
        'Partisi',
        'Partisi Alumunium',
        'Pintu',
        'Rak',
        'Renovasi Interior',
        'Renovasi Sipil',
        'Semi Full Home',
        'Sipil',
        'Walkin Closet',
        'Wall Moulding',
        'Wall Panel',
        'Wardrobe',
        'Meja Alumunium',
        'Cabinet Alumunium',
        self::OTHER_OPTION_LABEL,
        self::PENDING_LABEL,
        'Mihrab',
        'Interior Mushola',
        'Interior Salon',
        'Cafe Resto',
        'Interior laundry',
    ];

    protected $fillable = ['name'];

    public static function displayNames(): array
    {
        return self::DISPLAY_NAMES;
    }

    /**
     * Opsi kategori untuk form konsultasi dan dropdown template.
     * DISPLAY_NAMES menentukan urutan - nama kurasi tampil lebih dulu,
     * sisanya menyusul menurut abjad.
     */
    public function scopeForConsultationOptions($query)
    {
        $names = self::displayNames();
        $placeholders = implode(', ', array_fill(0, count($names), '?'));

        return $query
            ->orderByRaw("FIELD(name, {$placeholders}) = 0", $names)
            ->orderByRaw("FIELD(name, {$placeholders})", $names)
            ->orderBy('name');
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
