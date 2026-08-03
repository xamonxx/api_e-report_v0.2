<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Consultation;
use App\Models\ConsultationNote;
use App\Models\User;

class ConsultationNotePolicy
{
    /**
     * Super Admin bisa melakukan semua aksi pada catatan.
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->role === UserRole::SuperAdmin && $ability !== 'update') {
            return true;
        }

        return null;
    }

    /**
     * Menentukan apakah user bisa menghapus catatan.
     * Admin hanya bisa menghapus catatan miliknya sendiri
     * yang berada di konsultasi milik akunnya.
     */
    public function delete(User $user, ConsultationNote $note): bool
    {
        $consultation = Consultation::query()->find($note->consultation_id);

        if (!$consultation) {
            return false;
        }

        if ($user->account_id !== $consultation->account_id) {
            return false;
        }

        // Admin hanya bisa menghapus catatan miliknya sendiri
        return $note->user_id === $user->id;
    }

    /**
     * Isi pesan hanya boleh diubah oleh penulis aslinya, termasuk untuk
     * super admin. Ini menjaga identitas penulis dan histori percakapan.
     */
    public function update(User $user, ConsultationNote $note): bool
    {
        if ((int) $note->user_id !== (int) $user->id) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        $consultation = Consultation::query()->find($note->consultation_id);

        return $consultation
            && $user->isAdmin()
            && (int) $user->account_id === (int) $consultation->account_id;
    }
}
