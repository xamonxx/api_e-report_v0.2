<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\ReportAttendance;
use App\Models\User;
use App\Services\Reports\AdminReportAttendanceExcelExporter;
use App\Services\Reports\SpreadsheetXmlToXlsxConverter;
use App\Support\AccountGroup;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class ReportAttendanceController extends Controller
{
    private const REPORT_CATEGORY_OPTIONS = ['ada_wa', 'nol_wa', 'libur_susulan'];
    private const STATUS_FILTER_OPTIONS = ['all', 'ada_wa', 'nol_wa', 'libur_susulan', 'belum_laporan'];

    /** Batas panjang rentang rekap, disamakan dengan batas kolom export. */
    private const MAX_RECAP_DAYS = AdminReportAttendanceExcelExporter::MAX_RANGE_DAYS;

    /**
     * GET /api/v1/report-attendances (Super Admin Only)
     */
    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();
        if (!$user->isSuperAdmin() && !$user->isAdmin()) {
            return response()->json(['message' => 'Unauthorized. Super Admin role required.'], 403);
        }

        $selectedStatus = $request->get('status', 'all');

        if (!in_array($selectedStatus, self::STATUS_FILTER_OPTIONS, true)) {
            $selectedStatus = 'all';
        }

        // Mode rekap: aktif hanya kalau kedua ujung rentang dikirim. Bentuk
        // barisnya beda dari mode harian (satu admin bisa punya banyak hari),
        // jadi dipisah supaya mode harian tidak berubah sama sekali.
        if ($request->filled('start_date') && $request->filled('end_date')) {
            return $this->recapIndex($request, $user, $selectedStatus);
        }

        $dateParam = $request->get('date', Carbon::today()->format('Y-m-d'));
        $date = Carbon::parse($dateParam);

        $dateStr = $date->format('Y-m-d');

        $adminsQuery = User::where('role', UserRole::Admin)
            ->with(['account', 'reportAttendances' => fn($q) => $q->where('report_date', $dateStr)])
            ->orderBy('name');

        if ($user->isAdmin()) {
            $adminsQuery->whereKey($user->id);
        }

        $adminAttendances = $adminsQuery->get()
            ->map(function ($admin) {
                $attendance = $admin->reportAttendances->first();
                return [
                    'admin_id' => $admin->id,
                    'admin_name' => $admin->name,
                    'account_name' => $admin->account?->name ?? '-',
                    'account_description' => $admin->account?->description,
                    'has_reported' => $attendance !== null,
                    'reported_at' => $attendance?->created_at,
                    'report_category' => $attendance?->report_category,
                ];
            });

        $statusCounts = [
            'all' => $adminAttendances->count(),
            'ada_wa' => $adminAttendances->where('report_category', 'ada_wa')->count(),
            'nol_wa' => $adminAttendances->where('report_category', 'nol_wa')->count(),
            'libur_susulan' => $adminAttendances->where('report_category', 'libur_susulan')->count(),
            'belum_laporan' => $adminAttendances->where('has_reported', false)->count(),
        ];

        $filteredAttendances = match ($selectedStatus) {
            'ada_wa', 'nol_wa', 'libur_susulan' => $adminAttendances->where('report_category', $selectedStatus)->values(),
            'belum_laporan' => $adminAttendances->where('has_reported', false)->values(),
            default => $adminAttendances->values(),
        };

        return response()->json([
            'mode' => 'daily',
            'data' => $filteredAttendances,
            'status_counts' => $statusCounts,
            'date' => $dateStr,
            'selected_status' => $selectedStatus,
        ]);
    }

    /**
     * Rekap absensi untuk rentang tanggal bebas.
     *
     * Satu baris per admin berisi jumlah hari per kategori. Semua angka pada
     * `status_counts` memakai satuan yang sama — hari-admin (jumlah admin x
     * jumlah hari) — supaya kartu KPI bisa dibandingkan langsung.
     */
    private function recapIndex(Request $request, User $user, string $selectedStatus): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ], [
            'start_date.required' => 'Tanggal awal wajib diisi.',
            'end_date.required' => 'Tanggal akhir wajib diisi.',
            'end_date.after_or_equal' => 'Tanggal akhir tidak boleh sebelum tanggal awal.',
        ]);

        $start = Carbon::parse($validated['start_date'])->startOfDay();
        $end = Carbon::parse($validated['end_date'])->startOfDay();

        $maxEnd = $start->copy()->addDays(self::MAX_RECAP_DAYS - 1);
        $truncated = $end->gt($maxEnd);
        if ($truncated) {
            $end = $maxEnd;
        }

        $totalDays = $start->diffInDays($end) + 1;

        $adminsQuery = User::where('role', UserRole::Admin)
            ->with([
                'account',
                'reportAttendances' => fn ($q) => $q->whereBetween('report_date', [
                    $start->toDateString(),
                    $end->toDateString(),
                ]),
            ])
            ->orderBy('name');

        if ($user->isAdmin()) {
            $adminsQuery->whereKey($user->id);
        }

        $rows = $adminsQuery->get()->map(function (User $admin) use ($totalDays) {
            $byCategory = $admin->reportAttendances->countBy('report_category');
            $adaWa = (int) $byCategory->get('ada_wa', 0);
            $nolWa = (int) $byCategory->get('nol_wa', 0);
            $libur = (int) $byCategory->get('libur_susulan', 0);
            $reported = $adaWa + $nolWa + $libur;

            return [
                'admin_id' => $admin->id,
                'admin_name' => $admin->name,
                'account_name' => $admin->account?->name ?? '-',
                'account_description' => $admin->account?->description,
                'ada_wa' => $adaWa,
                'nol_wa' => $nolWa,
                'libur_susulan' => $libur,
                'reported_days' => $reported,
                'missing_days' => max($totalDays - $reported, 0),
                'total_days' => $totalDays,
                'compliance_rate' => $totalDays > 0 ? round(($reported / $totalDays) * 100, 1) : 0.0,
            ];
        });

        $statusCounts = [
            'all' => $rows->count() * $totalDays,
            'ada_wa' => (int) $rows->sum('ada_wa'),
            'nol_wa' => (int) $rows->sum('nol_wa'),
            'libur_susulan' => (int) $rows->sum('libur_susulan'),
            'belum_laporan' => (int) $rows->sum('missing_days'),
        ];

        // Filter kategori menyaring admin yang punya minimal satu hari di
        // kategori itu — bukan menyaring harinya, karena satu baris di sini
        // mewakili seluruh rentang.
        $filtered = match ($selectedStatus) {
            'ada_wa', 'nol_wa', 'libur_susulan' => $rows->where($selectedStatus, '>', 0)->values(),
            'belum_laporan' => $rows->where('missing_days', '>', 0)->values(),
            default => $rows->values(),
        };

        return response()->json([
            'mode' => 'recap',
            'data' => $filtered,
            'status_counts' => $statusCounts,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'total_days' => $totalDays,
            'admin_count' => $rows->count(),
            'range_truncated' => $truncated,
            'max_range_days' => self::MAX_RECAP_DAYS,
            'selected_status' => $selectedStatus,
        ]);
    }

    /**
     * POST /api/v1/report-attendances (Admin Only)
     */
    public function store(Request $request): JsonResponse
    {
        $user = auth()->user();
        if (!$user->isAdmin()) {
            return response()->json(['message' => 'Hanya admin yang dapat melakukan absensi report.'], 403);
        }

        $request->validate([
            'report_category' => 'required|in:' . implode(',', self::REPORT_CATEGORY_OPTIONS),
        ], [
            'report_category.required' => 'Pilih kategori laporan absen Anda.',
            'report_category.in' => 'Kategori yang dipilih tidak valid.'
        ]);

        $today = Carbon::today();

        $inserted = DB::table('report_attendances')->insertOrIgnore([
            'user_id' => $user->id,
            'account_id' => $user->account_id,
            'report_date' => $today->toDateString(),
            'report_category' => $request->report_category,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted === 0) {
            return response()->json([
                'message' => 'Anda sudah melakukan absensi hari ini.',
            ], 422);
        }

        return response()->json([
            'message' => 'Berhasil melakukan absensi report harian!',
        ], 201);
    }

    /**
     * POST /api/v1/report-attendances/upsert-by-super-admin (Super Admin Only)
     */
    public function upsertBySuperAdmin(Request $request): JsonResponse
    {
        $user = auth()->user();
        if (!$user->isSuperAdmin()) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'report_date' => 'required|date',
            'report_category' => 'nullable|in:' . implode(',', self::REPORT_CATEGORY_OPTIONS),
        ], [
            'user_id.required' => 'Admin wajib dipilih.',
            'user_id.exists' => 'Admin tidak valid.',
            'report_date.required' => 'Tanggal laporan wajib diisi.',
            'report_category.in' => 'Status absensi tidak valid.',
        ]);

        $admin = User::where('role', UserRole::Admin)->findOrFail($validated['user_id']);
        $reportDate = Carbon::parse($validated['report_date'])->toDateString();

        $attendance = ReportAttendance::firstOrNew([
            'user_id' => $admin->id,
            'account_id' => $admin->account_id,
            'report_date' => $reportDate,
        ]);

        if (blank($validated['report_category'])) {
            if ($attendance->exists) {
                $attendance->delete();
            }

            return response()->json([
                'message' => 'Status absensi admin berhasil diubah menjadi belum laporan.',
            ]);
        }

        $attendance->fill([
            'account_id' => $admin->account_id,
            'report_category' => $validated['report_category'],
        ]);
        $attendance->save();

        return response()->json([
            'message' => 'Status absensi admin berhasil diperbarui.',
            'data' => $attendance,
        ]);
    }

    /**
     * GET /api/v1/report-attendances/export (Super Admin Only)
     */
    public function export(
        Request $request,
        AdminReportAttendanceExcelExporter $excelExporter,
        SpreadsheetXmlToXlsxConverter $xlsxConverter
    ): Response {
        $user = auth()->user();
        if (!$user->isSuperAdmin()) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        // Terima "npp 2" / "NPP 2" / "npp2" -> "NPP2". Nilai tak dikenal
        // dibiarkan lewat supaya ditolak Rule::in dengan pesan yang jelas.
        if ($request->filled('account_group')) {
            $request->merge([
                'account_group' => AccountGroup::normalize($request->input('account_group'))
                    ?? $request->input('account_group'),
            ]);
        }

        $validated = $request->validate([
            'date' => ['nullable', 'date'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'account_group' => ['nullable', Rule::in(AccountGroup::values())],
        ], [
            'end_date.after_or_equal' => 'Tanggal akhir tidak boleh sebelum tanggal awal.',
            'account_group.in' => 'Grup akun tidak valid. Pilih salah satu: '
                . implode(', ', AccountGroup::labels()) . '.',
        ]);

        // Grup kosong berarti semua grup dalam satu lembar.
        $accountGroup = $validated['account_group'] ?? null;
        $hasRange = filled($validated['start_date'] ?? null) && filled($validated['end_date'] ?? null);

        // Rentang kustom kalau dikirim; kalau tidak, tetap satu bulan penuh
        // dari `date` seperti sebelumnya.
        $start = Carbon::parse(
            $hasRange
                ? $validated['start_date']
                : ($validated['date'] ?? Carbon::today()->format('Y-m-d'))
        );
        $end = $hasRange ? Carbon::parse($validated['end_date']) : null;

        $groupSlug = strtolower($accountGroup ?? 'semua-grup');

        $filename = $hasRange
            ? sprintf(
                'rekap-laporan-admin-%s-%s-%s.xlsx',
                $groupSlug,
                $start->format('Ymd'),
                $end->format('Ymd')
            )
            : sprintf('rekap-laporan-admin-%s-%s.xlsx', $groupSlug, $start->format('Y-m'));

        return response($xlsxConverter->convert($excelExporter->buildWorkbook($start, $accountGroup, $end)), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'max-age=0',
        ]);
    }
}
