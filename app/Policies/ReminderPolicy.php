<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Reminder;
use App\Models\User;

class ReminderPolicy
{
    /**
     * Super Admin bisa melakukan semua aksi pada reminder.
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->role === UserRole::SuperAdmin) {
            return true;
        }

        return null;
    }

    /**
     * Menentukan apakah user bisa menandai reminder sebagai sudah dibaca.
     * Hanya pemilik reminder yang bisa menandainya.
     */
    public function markAsRead(User $user, Reminder $reminder): bool
    {
        return $reminder->user_id === $user->id;
    }

    /**
     * Menentukan apakah user bisa menghapus reminder.
     * Admin harus dalam akun yang sama dan menjadi pemilik reminder.
     */
    public function delete(User $user, Reminder $reminder): bool
    {
        $consultation = $reminder->consultation;

        // Admin harus dalam akun yang sama dengan konsultasi
        if ($user->account_id !== $consultation->account_id) {
            return false;
        }

        // Pemilik reminder (assignee) atau pembuat (creator) yang bisa menghapus
        return $reminder->creator_id === $user->id || $reminder->user_id === $user->id;
    }
}
