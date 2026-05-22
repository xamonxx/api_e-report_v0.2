<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consultation;
use App\Models\NeedsCategory;
use App\Models\StatusCategory;
use App\Models\Account;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class DebugController extends Controller
{
    /**
     * GET /api/v1/debug/stats
     * Get data density stats, duplicate count, and latency.
     */
    public function stats(Request $request): JsonResponse
    {
        $user = auth()->user();
        
        // Measure database response latency
        $start = microtime(true);
        DB::select('SELECT 1');
        $latencyMs = round((microtime(true) - $start) * 1000, 2);

        // Fetch counts scoped to user (Admin only sees their own account's data)
        $totalLeads = Consultation::forUser($user)->count();
        $dummyLeads = Consultation::forUser($user)->where('notes', 'like', '[DUMMY]%')->count();
        $realLeads = $totalLeads - $dummyLeads;

        // Calculate potential duplicate leads (using phone number on same account as index)
        // Admin only checks their account
        $duplicateQuery = DB::table('consultations')
            ->select('account_id', 'phone', DB::raw('COUNT(*) as count'))
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->whereNull('deleted_at');

        if ($user->isAdmin()) {
            $duplicateQuery->where('account_id', $user->account_id);
        }

        $duplicateCount = $duplicateQuery->groupBy('account_id', 'phone')
            ->having('count', '>', 1)
            ->get()
            ->count();

        // Get monthly lead distribution for the last 12 months
        $monthlyDistribution = [];
        for ($i = 11; $i >= 0; $i--) {
            $targetMonth = Carbon::now()->subMonths($i);
            $monthNum = (int) $targetMonth->format('m');
            $yearNum = (int) $targetMonth->format('Y');
            
            $monthCount = Consultation::forUser($user)
                ->whereMonth('consultation_date', $monthNum)
                ->whereYear('consultation_date', $yearNum)
                ->count();

            $monthlyDistribution[] = [
                'label' => $targetMonth->translatedFormat('M Y'),
                'month' => $monthNum,
                'year' => $yearNum,
                'count' => $monthCount,
            ];
        }

        // Leads by status breakdown
        $statusBreakdown = [];
        $statuses = StatusCategory::orderBy('sort_order')->get();
        foreach ($statuses as $status) {
            $statusCount = Consultation::forUser($user)
                ->where('status_category_id', $status->id)
                ->count();

            $statusBreakdown[] = [
                'name' => $status->name,
                'color' => $status->color,
                'count' => $statusCount,
            ];
        }

        return response()->json([
            'latency_ms' => $latencyMs,
            'total_leads' => $totalLeads,
            'dummy_leads' => $dummyLeads,
            'real_leads' => $realLeads,
            'duplicate_leads' => $duplicateCount,
            'monthly_distribution' => $monthlyDistribution,
            'status_breakdown' => $statusBreakdown,
        ]);
    }

    /**
     * POST /api/v1/debug/generate-dummy
     * Seeds dummy Indonesian lead data.
     */
    public function generateDummy(Request $request): JsonResponse
    {
        $user = auth()->user();
        
        $validated = $request->validate([
            'count' => ['required', 'integer', 'min:1', 'max:2000'],
        ]);

        $count = $validated['count'];

        // Determine accounts to assign leads to
        if ($user->isAdmin()) {
            $accountIds = [$user->account_id];
        } else {
            $accountIds = Account::pluck('id')->all();
            if (empty($accountIds)) {
                $accountIds = [1];
            }
        }

        // Get config geography or define fallback
        $provinces = config('wilayah.provinces', []);
        if (empty($provinces)) {
            $provinces = ['JAWA BARAT', 'DKI JAKARTA', 'BANTEN', 'JAWA TENGAH', 'JAWA TIMUR', 'DI YOGYAKARTA'];
        }
        $cityMapping = config('wilayah_kota.mapping', []);
        $districtMapping = config('wilayah_kecamatan.mapping', []);

        // Master lists
        $needsCategories = NeedsCategory::all();
        $statusCategories = StatusCategory::all();

        // Indonesian random name sets
        $firstNames = ['Budi', 'Joko', 'Ani', 'Siti', 'Pratama', 'Dewi', 'Rian', 'Hendra', 'Eko', 'Slamet', 'Agus', 'Bambang', 'Sri', 'Kartika', 'Rina', 'Mega', 'Andi', 'Ahmad', 'Aditya', 'Rizky', 'Faisal', 'Dian', 'Putri', 'Wulandari', 'Indah', 'Fitri', 'Nur', 'Taufik', 'Hasan', 'Yudi', 'Dedi', 'Asep', 'Dadang', 'Cecep', 'Ujang', 'Agung', 'Doni', 'Genta', 'Bagas', 'Dimas', 'Reza', 'Denny', 'Ari', 'Guntur', 'Surya', 'Fajar', 'Tegar', 'Wahyu', 'Bayu', 'Fadilah', 'Lutfi', 'Rudi', 'Wawan', 'Deni', 'Hari', 'Iwan'];
        $lastNames = ['Wijaya', 'Pratama', 'Kusuma', 'Santoso', 'Hidayat', 'Saputra', 'Sari', 'Lestari', 'Wulandari', 'Utami', 'Setiawan', 'Budiman', 'Gunawan', 'Sutrisno', 'Hadi', 'Siregar', 'Nugroho', 'Prasetyo', 'Rahayu', 'Situmorang', 'Nasution', 'Lubis', 'Sinaga', 'Harahap', 'Ginting', 'Sembiring', 'Pane', 'Tanjung', 'Pohan', 'Hasibuan', 'Tarigan', 'Marpaung', 'Simanjuntak', 'Rajagukguk', 'Nainggolan', 'Napitupulu', 'Sianipar', 'Aritonang', 'Siburian', 'Lumbantoruan', 'Manurung'];
        $phonePrefixes = ['0812', '0813', '0821', '0822', '0852', '0853', '0811', '0817', '0818', '0819', '0859', '0877', '0878', '0838', '0831', '0832', '0856', '0857', '0858', '0895', '0896', '0897', '0898', '0899'];
        
        $productDetails = [
            'Kebutuhan renovasi ruang tamu minimalis modern dengan partisi kisi-kisi kayu.',
            'Kitchen set letter L dengan finish HPL woodgrain dan countertop granit hitam marquina.',
            'Lemari pakaian ceiling height pintu slide kaca tempered grey frame aluminium.',
            'Pekerjaan backdrop TV dengan lampu LED strip warm white dan kabinet gantung.',
            'Desain interior kamar tidur anak laki-laki tema astronot + tempat tidur sorong.',
            'Interior kantor space meeting room kapasitas 10 orang dengan acoustic panel.',
            'Renovasi toilet kering, pasang shower glass partition, vanity mirror led, dan keramik subway.',
            'Pemasangan vinyl flooring tebal 3mm motif oak wood seluas 45 meter persegi.',
            'Pembuatan kanopi carport kaca tempered flat metal frame double minimalis.',
            'Desain bed headboard mewah dengan panel cushion suede abu-abu kombinasi gold mirror.'
        ];
        
        $dummyNotes = [
            'Tertarik setelah melihat iklan di Instagram. Ingin diskon tambahan.',
            'Butuh cepat sebelum hari raya. Minta dijadwalkan survey minggu depan.',
            'Menghubungi lewat WhatsApp. Menanyakan estimasi biaya kasar per meter persegi.',
            'Tanya-tanya model wardrobe pintu kaca sliding. Budget terbatas.',
            'Klien sangat kooperatif, minta dikirimkan portofolio pengerjaan kitchen set di apartment.',
            'Masih membandingkan dengan vendor lain. Follow up 3 hari lagi.',
            'Nomor telepon agak susah dihubungi, coba email atau hubungi via whatsapp chat.',
            'Referensi desain dikirim via WA. Ingin nuansa Japandi modern.',
            'Minta survey di hari sabtu jam 10 pagi karena hari biasa sibuk bekerja.',
            'Sudah pernah deal sebelumnya untuk pengerjaan cabinet tv. Repeat order.'
        ];

        $generatedCount = 0;
        
        // Chunk generation in a transaction
        DB::transaction(function () use (
            $count, $accountIds, $provinces, $cityMapping, $districtMapping,
            $needsCategories, $statusCategories, $firstNames, $lastNames, $phonePrefixes,
            $productDetails, $dummyNotes, $user, &$generatedCount
        ) {
            $batchInsertPivot = [];
            
            for ($i = 0; $i < $count; $i++) {
                $accountId = $accountIds[array_rand($accountIds)];
                
                // Random month spread (over the last 12 months)
                $randomDaysAgo = mt_rand(0, 365);
                $consultationDate = Carbon::now()->subDays($randomDaysAgo);
                
                // Generate Consultation ID based on this specific month/year
                $consultationIdCode = Consultation::generateConsultationId($accountId, $consultationDate);
                
                $clientName = $firstNames[array_rand($firstNames)] . ' ' . $lastNames[array_rand($lastNames)];
                $phone = $phonePrefixes[array_rand($phonePrefixes)] . mt_rand(10000000, 99999999);
                
                // Pick Province, City, and District
                $province = $provinces[array_rand($provinces)];
                $city = 'Luar Kota';
                $district = null;
                
                $provinceClean = trim(strtolower($province));
                $provinceCities = array_keys(array_filter($cityMapping, function ($provName) use ($provinceClean) {
                    return trim(strtolower($provName)) === $provinceClean;
                }));
                
                if (!empty($provinceCities)) {
                    $city = $provinceCities[array_rand($provinceCities)];
                    $cityClean = trim(strtolower($city));
                    $cityDistricts = array_map(function ($item) {
                        return $item['district'] ?? '';
                    }, array_filter($districtMapping, function ($info) use ($cityClean) {
                        return str_contains(strtolower($info['city'] ?? ''), $cityClean);
                    }));
                    if (!empty($cityDistricts)) {
                        $district = $cityDistricts[array_rand($cityDistricts)];
                    }
                }

                $needsCategory = $needsCategories->isNotEmpty() ? $needsCategories->random() : null;
                $statusCategory = $statusCategories->isNotEmpty() ? $statusCategories->random() : null;
                
                $address = 'Jl. ' . $firstNames[array_rand($firstNames)] . ' No. ' . mt_rand(1, 100);
                $details = $productDetails[array_rand($productDetails)];
                $notes = '[DUMMY] ' . $dummyNotes[array_rand($dummyNotes)];

                // Create lead
                $lead = Consultation::create([
                    'consultation_id' => $consultationIdCode,
                    'client_name' => $clientName,
                    'phone' => $phone,
                    'province' => $province,
                    'city' => $city,
                    'district' => $district,
                    'address' => $address,
                    'account_id' => $accountId,
                    'needs_category_id' => $needsCategory ? $needsCategory->id : null,
                    'product_details' => $details,
                    'status_category_id' => $statusCategory ? $statusCategory->id : null,
                    'notes' => $notes,
                    'created_by' => $user->id,
                    'consultation_date' => $consultationDate->toDateString(),
                ]);

                // Prepare pivot entries if supported
                if ($needsCategory && Consultation::hasNeedsCategoryPivot()) {
                    $batchInsertPivot[] = [
                        'consultation_id' => $lead->id,
                        'needs_category_id' => $needsCategory->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                $generatedCount++;
            }

            // Write pivots in one go
            if (!empty($batchInsertPivot)) {
                DB::table('consultation_needs_category')->insertOrIgnore($batchInsertPivot);
            }

            // Flush dashboard caches
            foreach ($accountIds as $accId) {
                Cache::forget("dashboard:admin:{$accId}");
            }
            Cache::forget('dashboard:super_admin:' . $user->id);
        });

        return response()->json([
            'message' => "Berhasil menghasilkan {$generatedCount} data lead dummy!",
            'generated_count' => $generatedCount,
        ], 201);
    }

    /**
     * POST /api/v1/debug/clear-dummy
     * Force deletes dummy leads.
     */
    public function clearDummy(Request $request): JsonResponse
    {
        $user = auth()->user();
        
        $query = Consultation::where('notes', 'like', '[DUMMY]%');
        
        if ($user->isAdmin()) {
            $query->where('account_id', $user->account_id);
        }

        $dummyCount = $query->count();

        // Perform force delete to clear fully
        $query->forceDelete();

        // Flush caches
        if ($user->isAdmin()) {
            Cache::forget("dashboard:admin:{$user->account_id}");
        } else {
            $accountIds = Account::pluck('id')->all();
            foreach ($accountIds as $accId) {
                Cache::forget("dashboard:admin:{$accId}");
            }
        }
        Cache::forget('dashboard:super_admin:' . $user->id);

        return response()->json([
            'message' => "Berhasil membersihkan {$dummyCount} data lead dummy!",
            'cleared_count' => $dummyCount,
        ]);
    }

    /**
     * POST /api/v1/debug/clear-logs
     * Clears system logs (Laravel logs & Audit logs table).
     */
    public function clearLogs(Request $request): JsonResponse
    {
        $user = auth()->user();
        
        // Only Super Admins can clear system logs
        if (!$user->isSuperAdmin()) {
            return response()->json(['message' => 'Hanya Super Admin yang dapat membersihkan log sistem.'], 403);
        }

        $clearedAppLogs = 0;
        $clearedAuditLogs = 0;

        // 1. Clear database audit logs
        try {
            $clearedAuditLogs = AuditLog::count();
            Schema::disableForeignKeyConstraints();
            AuditLog::truncate();
            Schema::enableForeignKeyConstraints();
        } catch (\Exception $e) {
            // Log fallback or error
        }

        // 2. Clear Laravel file logs
        $logPath = storage_path('logs');
        if (is_dir($logPath)) {
            $logFiles = glob($logPath . '/*.log');
            foreach ($logFiles as $file) {
                if (is_file($file)) {
                    // Truncate the file
                    file_put_contents($file, '');
                    $clearedAppLogs++;
                }
            }
        }

        return response()->json([
            'message' => 'Berhasil membersihkan log sistem!',
            'cleared_audit_count' => $clearedAuditLogs,
            'cleared_app_logs_count' => $clearedAppLogs,
        ]);
    }
}
