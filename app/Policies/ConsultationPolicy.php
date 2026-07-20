<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Consultation;
use App\Models\User;

class ConsultationPolicy
{
    /**
     * Super Admin bisa mengakses semua konsultasi.
     * Jika return true, method policy lain akan di-bypass.
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->role === UserRole::SuperAdmin) {
            return true;
        }

        return null;
    }

    /**
     * Menentukan apakah user bisa melihat daftar konsultasi.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Menentukan apakah user bisa melihat detail konsultasi tertentu.
     */
    public function view(User $user, Consultation $consultation): bool
    {
        return $user->isAdmin() && $user->account_id === $consultation->account_id;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Consultation $consultation): bool
    {
        return $user->isAdmin() && $user->account_id === $consultation->account_id;
    }

    public function delete(User $user, Consultation $consultation): bool
    {
        return $user->isAdmin() && $user->account_id === $consultation->account_id;
    }

    public function viewHistory(User $user, Consultation $consultation): bool
    {
        return $user->isAdmin() && $user->account_id === $consultation->account_id;
    }

    public function addNote(User $user, Consultation $consultation): bool
    {
        return $user->isAdmin() && $user->account_id === $consultation->account_id;
    }

    public function addReminder(User $user, Consultation $consultation): bool
    {
        return $user->isAdmin() && $user->account_id === $consultation->account_id;
    }
}
