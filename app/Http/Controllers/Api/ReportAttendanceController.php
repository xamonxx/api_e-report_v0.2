<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\ReportAttendance;
use App\Models\User;
use App\Services\Reports\AdminReportAttendanceExcelExporter;
use App\Services\Reports\SpreadsheetXmlToXlsxConverter;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ReportAttendanceController extends Controller
{
    private const REPORT_CATEGORY_OPTIONS = ['ada_wa', 'nol_wa', 'libur_susulan'];
    private const STATUS_FILTER_OPTIONS = ['all', 'ada_wa', 'nol_wa', 'libur_susulan', 'belum_laporan'];

    /**
     * GET /api/v1/report-attendances (Super Admin Only)
     */
    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();
        if (!$user->isSuperAdmin() && !$user->isAdmin()) {
            return response()->json(['message' => 'Unauthorized. Super Admin role required.'], 403);
        }

        $dateParam = $request->get('date', Carbon::today()->format('Y-m-d'));
        $date = Carbon::parse($dateParam);
        $selectedStatus = $request->get('status', 'all');

        if (!in_array($selectedStatus, self::STATUS_FILTER_OPTIONS, true)) {
            $selectedStatus = 'all';
        }

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
            'data' => $filteredAttendances,
            'status_counts' => $statusCounts,
            'date' => $dateStr,
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

        $validated = $request->validate([
            'date' => ['nullable', 'date'],
            'account_group' => ['required', 'in:PC,NPP'],
        ]);

        $date = Carbon::parse($validated['date'] ?? Carbon::today()->format('Y-m-d'));
        $accountGroup = $validated['account_group'];
        $filename = sprintf('rekap-laporan-admin-%s-%s.xlsx', strtolower($accountGroup), $date->format('Y-m'));

        return response($xlsxConverter->convert($excelExporter->buildWorkbook($date, $accountGroup)), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'max-age=0',
        ]);
    }
}
