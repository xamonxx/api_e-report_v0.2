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
     * Urutan tampil kategori bawaan. Ini hanya PETUNJUK URUTAN dan sumber
     * seeder - bukan daftar putih. Kategori yang ditambahkan lewat Master Data
     * tetap muncul, ditempatkan setelah nama-nama di bawah ini.
     */
    public const DISPLAY_NAMES = [
        self::PENDING_LABEL,
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

    /**
     * Opsi kategori untuk form konsultasi dan dropdown template.
     *
     * Sengaja TIDAK memfilter nama: sebelumnya scope ini memakai
     * whereIn('name', DISPLAY_NAMES), sehingga kategori yang ditambahkan lewat
     * halaman Master Data tidak pernah muncul di form maupun template.
     * DISPLAY_NAMES kini hanya menentukan urutan - nama kurasi tampil lebih
     * dulu, sisanya menyusul menurut abjad.
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
