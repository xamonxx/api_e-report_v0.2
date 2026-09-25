<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Survey;
use App\Models\User;

class SurveyPolicy
{
    /**
     * Super Admin bypass semua ability.
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->role === UserRole::SuperAdmin) {
            return true;
        }

        return null;
    }

    /**
     * Melihat daftar survey (/surveys): tim survey pusat dan admin terbatas.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasSurveyTeam() || $user->isAdmin();
    }

    public function viewAvailability(User $user): bool
    {
        return $user->isManagerSurveyor() && $user->hasSurveyTeam();
    }

    public function viewRecap(User $user): bool
    {
        return $user->isManagerSurveyor() && $user->hasSurveyTeam();
    }

    /**
     * Melihat satu survey:
     * - manager surveyor: tim akun yang sama.
     * - surveyor: hanya miliknya dalam tim yang sama.
     * - admin: hanya survey yang dia ajukan sendiri di akunnya.
     */
    public function view(User $user, Survey $survey): bool
    {
        if ($user->isManagerSurveyor()) {
            return Survey::query()->visibleTo($user)->whereKey($survey->id)->exists();
        }

        if ($user->isSurveyor()) {
            return Survey::query()->visibleTo($user)->whereKey($survey->id)->exists();
        }

        if ($user->isAdmin()) {
            return (int) $survey->account_id === (int) $user->account_id
                && (int) $survey->requested_by === (int) $user->id;
        }

        return false;
    }

    /**
     * Menugaskan surveyor + jadwal (requested â†’ scheduled): manager surveyor.
     */
    public function assign(User $user, Survey $survey): bool
    {
        return $user->isManagerSurveyor() && $this->view($user, $survey);
    }

    /**
     * Reschedule jadwal yang diajukan (requested_date/time) oleh admin akun
     * yang sama. Berlaku selama survey belum berjalan/selesai/batal.
     */
    public function reschedule(User $user, Survey $survey): bool
    {
        return $user->isAdmin()
            && (int) $survey->account_id === (int) $user->account_id
            && (int) $survey->requested_by === (int) $user->id;
    }

    /**
     * Admin akun terkait boleh melengkapi/memperbaiki link Google Maps pada
     * survey aktif yang sudah diajukan.
     */
    public function updateMaps(User $user, Survey $survey): bool
    {
        return $user->isAdmin()
            && (int) $survey->account_id === (int) $user->account_id
            && (int) $survey->requested_by === (int) $user->id;
    }

    /** Mengubah jadwal final atau surveyor: manager surveyor. */
    public function rescheduleAssignment(User $user, Survey $survey): bool
    {
        return $user->isManagerSurveyor() && $this->view($user, $survey);
    }

    /** Memulai survey: surveyor yang ditugaskan atau manager surveyor. */
    public function start(User $user, Survey $survey): bool
    {
        return $user->isSurveyTeam() && $this->view($user, $survey);
    }

    /**
     * Mengisi hasil survey (scheduled â†’ completed):
     * surveyor pemilik, atau manager surveyor.
     */
    public function submitResult(User $user, Survey $survey): bool
    {
        if ($user->isManagerSurveyor()) {
            return $this->view($user, $survey);
        }

        return $user->isSurveyor() && $this->view($user, $survey);
    }

    /**
     * Membatalkan survey:
     * - manager surveyor: survey operasional dalam timnya.
     * - admin: survey yang dia ajukan sendiri di akun/cabangnya.
     * - surveyor: survey yang ditugaskan kepadanya.
     */
    public function cancel(User $user, Survey $survey): bool
    {
        if ($user->isManagerSurveyor()) {
            return $this->view($user, $survey);
        }

        if ($user->isAdmin()) {
            return (int) $survey->account_id === (int) $user->account_id
                && (int) $survey->requested_by === (int) $user->id;
        }

        return $user->isSurveyor() && $this->view($user, $survey);
    }
}
