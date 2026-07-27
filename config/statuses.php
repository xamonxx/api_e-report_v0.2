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
| PENTING — 'survey' dan 'survey_entry' bukan hal yang sama:
|
|   'survey'       grup pelaporan: status apa saja yang dihitung sebagai survey
|                  di KPI, analitik, dan ekspor.
|   'survey_entry' satu status tempat survey boleh DIAJUKAN, dan tempat lead
|                  dianggap "menunggu pengajuan survey".
|
| Keduanya sempat jadi satu kunci. Waktu 'survey' diperluas jadi tiga status
| untuk keperluan pelaporan, gerbang pengajuannya ikut melebar tanpa disengaja —
| survey jadi bisa diajukan pada lead yang surveynya sudah selesai. Pisahkan.
|
*/

return [
    'deal' => 'Selesai/Deal',
    'survey' => ['Request Survey', 'Sedang Survey', 'Selesai Survey'],
    'survey_entry' => 'Request Survey',
    'pending' => ['Masih konsultasi', 'Nunggu Bangunan', 'Perbandingan Harga', 'Hanya Tanya Tanya'],
    'cancelled' => ['Kendala Anggaran', 'Tidak Ada Respon'],
];
