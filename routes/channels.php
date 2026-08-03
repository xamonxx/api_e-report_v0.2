<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Otorisasi channel privat survey. Tanpa file ini channel harus publik,
| sehingga siapa pun yang terhubung ke server WebSocket bisa membaca pesan
| notifikasi survey (nama klien, wilayah, nama surveyor, jadwal).
|
*/

// Antrian & pembaruan survey untuk tim pusat.
Broadcast::channel('survey.managers', function (User $user) {
    return $user->isManagerSurveyor() || $user->isSuperAdmin();
});

// Kanal pribadi per surveyor: hanya pemilik, manager, dan super admin.
Broadcast::channel('survey.surveyor.{surveyorId}', function (User $user, int $surveyorId) {
    return (int) $user->id === $surveyorId
        || $user->isManagerSurveyor()
        || $user->isSuperAdmin();
});

// Kanal per akun/cabang agar admin mendapatkan pembaruan survey real-time
// untuk lead miliknya, termasuk cancel/reschedule dari tim survey.
Broadcast::channel('survey.account.{accountId}', function (User $user, int $accountId) {
    return $user->isSuperAdmin()
        || ($user->isAdmin() && (int) $user->account_id === $accountId);
});

// Kanal catatan dipisah per pengguna agar payload tidak pernah tersebar ke
// akun atau role lain, walaupun nama channel berhasil ditebak.
Broadcast::channel('consultation-notes.user.{userId}', function (User $user, int $userId) {
    return (int) $user->id === $userId
        && ($user->isAdmin() || $user->isSuperAdmin());
});
