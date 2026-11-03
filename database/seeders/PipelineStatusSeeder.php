<?php

namespace Database\Seeders;

use App\Models\StatusCategory;
use Illuminate\Database\Seeder;

class PipelineStatusSeeder extends Seeder
{
    public const STATUSES = [
        ['name' => 'Hanya Tanya Tanya', 'color' => '#eab308', 'css_class' => 'chip-hanya-tanya', 'sort_order' => 1],
        ['name' => 'Request Survey', 'color' => '#8582ff', 'css_class' => 'chip-request-survey', 'sort_order' => 2],
        ['name' => 'Kendala Anggaran', 'color' => '#9f403d', 'css_class' => 'chip-kendala-anggaran', 'sort_order' => 3],
        ['name' => 'Tidak Ada Respon', 'color' => '#737c7f', 'css_class' => 'chip-tidak-ada-respon', 'sort_order' => 4],
        ['name' => 'Selesai/Deal', 'color' => '#006d4a', 'css_class' => 'chip-selesai-deal', 'sort_order' => 5],
        ['name' => 'Masih konsultasi', 'color' => '#2563eb', 'css_class' => 'chip-masih-konsultasi', 'sort_order' => 6],
        ['name' => 'Nunggu Bangunan', 'color' => '#0f97ff', 'css_class' => 'chip-nunggu-bangunan', 'sort_order' => 7],
        ['name' => 'Perbandingan Harga', 'color' => '#0ca5c9', 'css_class' => 'chip-perbandingan-harga', 'sort_order' => 8],
        ['name' => 'Sedang Survey', 'color' => '#737c7f', 'css_class' => 'bg-violet-500/10 text-violet-500', 'sort_order' => 9],
        ['name' => 'Selesai Survey', 'color' => '#737c7f', 'css_class' => 'bg-emerald-500/10 text-emerald-500', 'sort_order' => 10],
    ];

    public function run(): void
    {
        foreach (self::STATUSES as $status) {
            $existing = StatusCategory::whereRaw('LOWER(name) = ?', [strtolower($status['name'])])->first();
            if ($existing) {
                $existing->update([
                    'name' => $status['name'],
                    'color' => $status['color'],
                    'sort_order' => $status['sort_order'],
                    'css_class' => $status['css_class'],
                ]);
            } else {
                StatusCategory::create($status);
            }
        }
    }
}
