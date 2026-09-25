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
    return $user->isSuperAdmin();
});

Broadcast::channel('survey.managers.{team}', function (User $user, string $team) {
    return in_array($team, ['A', 'B', 'C', 'D', 'E', 'F'], true)
        && ($user->isSuperAdmin()
            || ($user->isManagerSurveyor() && $user->hasSurveyTeam() && $user->survey_team === $team));
});

// Kanal pribadi per surveyor: hanya pemilik, manager, dan super admin.
Broadcast::channel('survey.surveyor.{surveyorId}', function (User $user, int $surveyorId) {
    if ($user->isSuperAdmin()) {
        return true;
    }

    $surveyor = User::find($surveyorId);

    return $surveyor && $surveyor->isSurveyor() && $surveyor->hasSurveyTeam()
        && (($user->isSurveyor() && (int) $user->id === $surveyorId)
            || ($user->isManagerSurveyor() && $user->hasSurveyTeam() && $user->survey_team === $surveyor->survey_team));
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
