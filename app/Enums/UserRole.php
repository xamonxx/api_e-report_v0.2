<?php

namespace App\Enums;

enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case Surveyor = 'surveyor';
    case ManagerSurveyor = 'manager_surveyor';

    /**
     * Mendapatkan label yang ramah untuk ditampilkan di UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Admin => 'Admin',
            self::Surveyor => 'Surveyor',
            self::ManagerSurveyor => 'Manager Surveyor',
        };
    }

    /**
     * Mendapatkan semua value enum sebagai array (untuk validasi rule "in:...").
     *
     * @return string[]
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
