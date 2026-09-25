<?php

namespace App\Services\Reports;

/**
 * Hasil resolusi status satu akun+tanggal — dipakai kedua exporter recap
 * konsul (matrix kalender & log harian) supaya warna/label selalu konsisten.
 */
final class ConsultationRecapStatus
{
    public function __construct(
        public readonly string $style,
        public readonly int $count,
        public readonly string $label,
        public readonly string $description,
    ) {
    }
}
