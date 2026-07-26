<?php

/*
|--------------------------------------------------------------------------
| Pengelompokan status konsultasi
|--------------------------------------------------------------------------
|
| Nama harus cocok persis (huruf/spasi/pemisah diabaikan) dengan baris di
| tabel status_categories. Nama yang tidak cocok diabaikan diam-diam oleh
| App\Support\ConsultationStatusGroups, jadi jangan menaruh alias fiktif di
| sini — dulu 'pending' dan 'cancelled' berisi nama yang tidak ada satu pun di
| DB, sehingga KPI-nya selalu 0 tanpa error.
|
| Boleh string tunggal atau array.
|
*/

return [
    'deal' => 'Selesai/Deal',
    'survey' => ['Request Survey', 'Sedang Survey', 'Selesai Survey'],
    'pending' => ['Masih konsultasi', 'Nunggu Bangunan', 'Perbandingan Harga', 'Hanya Tanya Tanya'],
    'cancelled' => ['Kendala Anggaran', 'Tidak Ada Respon'],
];
