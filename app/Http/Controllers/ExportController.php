<?php

namespace App\Http\Controllers;

use App\Http\Requests\AnalyticsReportRequest;
use App\Models\Consultation;
use App\Services\Reports\AnalyticsExcelExporter;
use App\Services\Reports\AnalyticsReportService;
use App\Services\Reports\LeadsExcelExporter;
use App\Services\Reports\LeadsReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    /**
     * Optimized CSV export: select only needed columns to reduce memory usage.
     * Uses lazy() for chunked processing to handle large datasets.
     */
    public function exportCsv(Request $request): StreamedResponse
    {
        $user = auth()->user();
        $query = Consultation::query()
            ->select([
                'consultations.id',
                'consultations.consultation_id',
                'consultations.client_name',
                'consultations.phone',
                'consultations.province',
                'consultations.city',
                'consultations.account_id',
                'consultations.needs_category_id',
                'consultations.product_details',
                'consultations.status_category_id',
                'consultations.notes',
                'consultations.consultation_date',
                'consultations.created_by',
                'consultations.updated_at',
            ])
            ->withProductRelations();

        $query->forUser($user);

        if ($user->isSuperAdmin() && $request->filled('account')) {
            $query->where('account_id', $request->integer('account'));
        }

        if ($request->filled('month')) {
            $query->whereMonth('consultation_date', $request->month);
        }

        if ($request->filled('year')) {
            $query->whereYear('consultation_date', $request->year);
        }

        $filename = 'Data_Leads_' . now()->format('Ymd_His') . '.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');

            fputs($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputs($handle, "sep=,\n");

            fputcsv($handle, [
                'ID Konsultasi', 'Nama Klien', 'No. Telepon', 'Provinsi', 'Kota',
                'Akun', 'Nama Produk', 'Detail Produk', 'Status', 'Catatan',
                'Tanggal Konsultasi', 'Dibuat Oleh', 'Tanggal Update',
            ]);

            foreach ($query->orderBy('consultation_date', 'desc')->lazy(500) as $consultation) {
                $phone = $consultation->phone ? "'" . $consultation->phone : '';

                fputcsv($handle, [
                    $consultation->consultation_id,
                    $consultation->client_name,
                    $phone,
                    $consultation->province,
                    $consultation->city,
                    $consultation->account?->name,
                    $consultation->product_names_label,
                    $consultation->product_details,
                    $consultation->statusCategory?->name,
                    $consultation->getAttribute('notes'),
                    $consultation->consultation_date?->format('d/m/Y'),
                    $consultation->creator?->name,
                    $consultation->updated_at?->format('d/m/Y H:i'),
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Pragma' => 'public',
            'Cache-Control' => 'no-cache, must-revalidate',
        ]);
    }

    public function exportLeadsExcel(
        Request $request,
        LeadsReportService $reportService,
        LeadsExcelExporter $excelExporter
    ): Response {
        $report = $reportService->buildForUser($request->user(), $this->validatedLeadExportFilters($request));
        $filename = $this->leadsFilename('xls', $report);

        return response(
            $excelExporter->buildWorkbook($report),
            200,
            [
                'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Cache-Control' => 'max-age=0',
            ]
        );
    }

    public function exportLeadsPdf(Request $request, LeadsReportService $reportService): Response
    {
        $report = $reportService->buildForUser($request->user(), $this->validatedLeadExportFilters($request));
        $filename = $this->leadsFilename('pdf', $report);

        $pdf = Pdf::loadView('reports.pdf.leads-klasemen', $report)
            ->setPaper('a3', 'landscape');

        return $pdf->download($filename);
    }

    public function exportAnalyticsExcel(
        AnalyticsReportRequest $request,
        AnalyticsReportService $reportService,
        AnalyticsExcelExporter $excelExporter
    ): Response {
        $report = $reportService->buildForUser(
            $request->user(),
            $request->validated(),
            ['includeRawRows' => true]
        );
        $filename = $this->analyticsFilename('xls', $report);

        return response(
            $excelExporter->buildWorkbook($report),
            200,
            [
                'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Cache-Control' => 'max-age=0',
            ]
        );
    }

    public function exportAnalyticsPdf(
        AnalyticsReportRequest $request,
        AnalyticsReportService $reportService
    ): Response {
        $report = $reportService->buildForUser(
            $request->user(),
            $request->validated(),
            ['includeRawRows' => true]
        );
        $filename = $this->analyticsFilename('pdf', $report);

        $pdf = Pdf::loadView('reports.pdf.analytics', [
            ...$report,
            'generatedAt' => now(),
        ])->setPaper('a4', 'landscape');

        return $pdf->download($filename);
    }

    private function analyticsFilename(string $extension, array $report): string
    {
        $account = str($report['selectedAccountName'] ?? 'semua-akun')
            ->slug()
            ->toString();

        $period = str($report['period']['type'] ?? 'period')
            ->append('-')
            ->append($report['period']['start']->format('Ymd'))
            ->append('-')
            ->append($report['period']['end']->format('Ymd'))
            ->toString();

        return sprintf('laporan-analisis-%s-%s.%s', $account, $period, $extension);
    }

    private function validatedLeadExportFilters(Request $request): array
    {
        return $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'integer', 'exists:status_categories,id'],
            'account' => ['nullable', 'integer', 'exists:accounts,id'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
        ]);
    }

    private function leadsFilename(string $extension, array $report): string
    {
        $account = str($report['selectedAccountName'] ?? 'semua-akun')
            ->slug()
            ->toString();

        $period = str($report['periodLabel'] ?? 'periode')
            ->slug()
            ->toString();

        return sprintf('data-leads-%s-%s.%s', $account, $period, $extension);
    }
}
