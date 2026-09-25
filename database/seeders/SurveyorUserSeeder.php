<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use RuntimeException;

class SurveyorUserSeeder extends Seeder
{
    public function run(): void
    {
        throw new RuntimeException(
            'Seeder roster lama dinonaktifkan. Gunakan php artisan survey:sync-teams untuk dry-run, '
            .'lalu --apply --credentials=<path absolut di luar Git> setelah backup dan migration survey_team.'
        );
    }
}
