<?php

namespace App\Support;

final class PendingConfirmation
{
    /**
     * Label lama. Dulu dipakai untuk dua hal sekaligus: placeholder wilayah dan
     * nama baris NeedsCategory. Sekarang keduanya punya label sendiri, dan
     * konstanta ini hanya dipertahankan untuk mengenali data lama.
     */
    public const LEGACY_LABEL = 'Belum ada konfirmasi';

    /**
     * @deprecated Pakai REGION_LABEL untuk wilayah atau
     *             NeedsCategory::PENDING_LABEL untuk kategori kebutuhan.
     */
    public const LABEL = self::LEGACY_LABEL;

    /**
     * Placeholder provinsi / kota / kecamatan yang belum diisi.
     */
    public const REGION_LABEL = 'Belum konfirmasi';

    /**
     * True bila nilai adalah salah satu placeholder, bukan nama wilayah asli.
     * Pencocokan mengabaikan huruf besar-kecil dan spasi berlebih karena label
     * bolak-balik lewat UI yang menerapkan title-case pada input bebas.
     *
     * Label legacy ikut diterima supaya data lama tetap dikenali selama masa
     * transisi sebelum backfill dijalankan.
     */
    public static function matches(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        $clean = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? ''));

        foreach (self::labels() as $label) {
            if ($clean === mb_strtolower($label)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Semua label yang dianggap placeholder wilayah.
     *
     * @return list<string>
     */
    public static function labels(): array
    {
        return [self::REGION_LABEL, self::LEGACY_LABEL];
    }

    /**
     * Nilai kosong atau placeholder dikerucutkan ke label wilayah kanonik agar
     * perbandingan di hilir bisa memakai kecocokan persis.
     */
    public static function normalizeRegion(?string $value): string
    {
        if ($value === null || trim($value) === '' || self::matches($value)) {
            return self::REGION_LABEL;
        }

        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    /**
     * @deprecated Pakai normalizeRegion().
     */
    public static function normalize(?string $value): string
    {
        return self::normalizeRegion($value);
    }
}
