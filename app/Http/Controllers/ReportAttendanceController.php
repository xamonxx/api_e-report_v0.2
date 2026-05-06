<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use Illuminate\Http\Request;
use App\Models\ReportAttendance;
use Carbon\Carbon;
use App\Models\User;
use App\Services\Reports\AdminReportAttendanceExcelExporter;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ReportAttendanceController extends Controller
{
    private const REPORT_CATEGORY_OPTIONS = ['ada_wa', 'nol_wa', 'libur_susulan'];
    private const STATUS_FILTER_OPTIONS = ['all', 'ada_wa', 'nol_wa', 'libur_susulan', 'belum_laporan'];

    public function index(Request $request)
    {
        // Hanya Super Admin yang bisa mengakses monitoring
        $user = auth()->user();
        if (!$user->isSuperAdmin()) {
            abort(403);
        }

        $dateParam = $request->get('date', Carbon::today()->format('Y-m-d'));
        $date = Carbon::parse($dateParam);
        $selectedStatus = $request->get('status', 'all');

        if (!in_array($selectedStatus, self::STATUS_FILTER_OPTIONS, true)) {
            $selectedStatus = 'all';
        }

        $dateStr = $date->format('Y-m-d');

        $adminAttendances = User::where('role', UserRole::Admin)
            ->with(['account', 'reportAttendances' => fn($q) => $q->where('report_date', $dateStr)])
            ->get()
            ->map(function($admin) {
                $attendance = $admin->reportAttendances->first();
                return (object) [
                    'admin' => $admin,
                    'account' => $admin->account,
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
        $groupedAttendances = $filteredAttendances
            ->groupBy(fn ($attendance) => $this->accountGroupLabel($attendance->account?->description))
            ->sortBy(fn ($items, string $group) => $group === 'PC' ? 0 : 1);

        return view('report-attendances.index', [
            'adminAttendances' => $filteredAttendances,
            'groupedAttendances' => $groupedAttendances,
            'date' => $date,
            'selectedStatus' => $selectedStatus,
            'statusCounts' => $statusCounts,
        ]);
    }

    public function upsertBySuperAdmin(Request $request)
    {
        $user = auth()->user();
        if (!$user->isSuperAdmin()) {
            abort(403);
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

            return back()->with('success', 'Status absensi admin berhasil diubah menjadi belum laporan.');
        }

        $attendance->fill([
            'account_id' => $admin->account_id,
            'report_category' => $validated['report_category'],
        ]);
        $attendance->save();

        return back()->with('success', 'Status absensi admin berhasil diperbarui.');
    }

    public function export(Request $request, AdminReportAttendanceExcelExporter $excelExporter): Response
    {
        $user = auth()->user();
        if (!$user->isSuperAdmin()) {
            abort(403);
        }

        $validated = $request->validate([
            'date' => ['nullable', 'date'],
            'account_group' => ['required', 'in:PC,NPP'],
        ]);

        $date = Carbon::parse($validated['date'] ?? Carbon::today()->format('Y-m-d'));
        $accountGroup = $validated['account_group'];
        $filename = sprintf('rekap-laporan-admin-%s-%s.xls', strtolower($accountGroup), $date->format('Y-m'));

        return response($excelExporter->buildWorkbook($date, $accountGroup), 200, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    public function store(Request $request)
    {
        $user = auth()->user();

        // Hanya admin yang bisa absen
        if (!$user->isAdmin()) {
            return back()->with('error', 'Hanya admin yang dapat melakukan absensi report.');
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
            return back()->with('error', 'Anda sudah melakukan absensi hari ini.');
        }

        return back()->with('success', 'Berhasil melakukan absensi report harian!');
    }

    private function accountGroupLabel(?string $description): string
    {
        $normalized = str((string) $description)->upper()->squish()->toString();

        if (str_contains($normalized, 'PUTRA') || $normalized === 'PC') {
            return 'PC';
        }

        if (str_contains($normalized, 'NPP')) {
            return 'NPP';
        }

        return 'NPP';
    }
}
