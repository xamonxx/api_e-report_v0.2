<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class SurveyorUserSeeder extends Seeder
{
    /**
     * @var list<string>
     */
    private const NAMES = [
        'BAGJA',
        'DEDEN',
        'HANDI',
        'KUNKUN',
        'ADAM',
        'RAFLI',
        'DIAN',
        'SAUT',
        'FAISAL',
        'AGIL',
        'LUKI',
        'ANTO',
        'ALAN',
        'ADI',
        'RAMA',
        'CANDRA',
        'ADEN',
        'ANGGA',
        'DONI',
        'DIKI',
        'GANJAR',
        'INDRA DADO',
        'TRIA',
        'YOAN',
    ];

    public function run(): void
    {
        $password = env('SEED_SURVEYOR_PASSWORD');

        if (! is_string($password) || Str::length($password) < 8) {
            throw new RuntimeException(
                'SEED_SURVEYOR_PASSWORD wajib diisi dan minimal 8 karakter.'
            );
        }

        foreach (self::NAMES as $name) {
            $email = Str::of($name)
                ->lower()
                ->replace(' ', '.')
                ->append('-srvy@npp.com')
                ->toString();

            $legacyEmail = Str::of($name)
                ->lower()
                ->replace(' ', '.')
                ->append('@surveyor.ereport.local')
                ->toString();

            $user = User::withTrashed()->where('email', $email)->first()
                ?? User::withTrashed()->where('email', $legacyEmail)->first()
                ?? User::withTrashed()
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                    ->where('role', UserRole::Surveyor->value)
                    ->first()
                ?? new User();
            $isNew = ! $user->exists;

            $user->name = $name;
            $user->email = $email;
            $user->account_id = null;
            $user->role = UserRole::Surveyor;
            $user->password = Hash::make($password);

            if ($user->trashed()) {
                $user->restore();
            }

            $user->save();

            $status = $isNew ? '+' : '=';
            $this->command?->line("  {$status} {$name} <{$email}>");
        }
    }
}
