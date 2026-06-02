<?php

namespace App\Services\Reports;

use Illuminate\Support\Collection;

class AnalyticsExcelExporter
{
    private const INTRO_ROWS = 3;
    private const HEADER_ROW = 4;
    private const DATA_START_ROW = 5;

    public function buildWorkbook(array $report): string
    {
        $rawRowCount = max(($report['rawRows'] ?? collect())->count(), 1);
        $rawLastRow = self::DATA_START_ROW + $rawRowCount - 1;
        $trendRowCount = max(($report['trendSeries'] ?? collect())->count(), 1);
        $trendLastRow = self::DATA_START_ROW + $trendRowCount - 1;

        $worksheets = [
            $this->buildDashboardSheet($report, $rawLastRow, $trendLastRow),
            $this->buildAnalysisSheet($report),
            $this->buildTrendSheet($report['trendSeries'] ?? collect(), $report),
            $this->buildQualitySheet($report, $rawLastRow),
            $this->buildMetricSheet(
                'Status',
                'Distribusi status konsultasi pada periode terpilih.',
                $report['statusDistribution'] ?? collect(),
                [50, 220, 90, 90, 90, 110],
                withColor: true
            ),
            $this->buildMetricSheet(
                'Kebutuhan',
                'Distribusi kategori kebutuhan lead.',
                $report['needsDistribution'] ?? collect(),
                [50, 260, 90, 90, 110],
            ),
            $this->buildRegionSheet($report),
            $this->buildRankingSheet($report),
            $this->buildRawDataSheet($report['rawRows'] ?? collect(), $report),
        ];

        $xml = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<?mso-application progid="Excel.Sheet"?>',
            '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"'
            . ' xmlns:o="urn:schemas-microsoft-com:office:office"'
            . ' xmlns:x="urn:schemas-microsoft-com:office:excel"'
            . ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"'
            . ' xmlns:html="http://www.w3.org/TR/REC-html40">',
            $this->stylesXml(),
        ];

        foreach ($worksheets as $worksheet) {
            $xml[] = sprintf('<Worksheet ss:Name="%s">', $this->escapeSheetName($worksheet['name']));
            $xml[] = '<Table x:FullColumns="1" x:FullRows="1">';

            foreach ($worksheet['columns'] as $width) {
                $xml[] = sprintf('<Column ss:AutoFitWidth="0" ss:Width="%s"/>', (float) $width);
            }

            foreach ($worksheet['rows'] as $row) {
                $rowHeight = isset($row['height']) ? sprintf(' ss:Height="%s"', (float) $row['height']) : '';
                $xml[] = sprintf('<Row%s>', $rowHeight);

                foreach ($row['cells'] as $cell) {
                    $xml[] = $this->buildCell($cell);
                }

                $xml[] = '</Row>';
            }

            $xml[] = '</Table>';
            $xml[] = $this->worksheetOptionsXml($worksheet['freeze_rows'] ?? 1);
            $xml[] = '</Worksheet>';
        }

        $xml[] = '</Workbook>';

        return implode('', $xml);
    }

    private function buildDashboardSheet(array $report, int $rawLastRow, int $trendLastRow): array
    {
        $columns = [175, 120, 175, 120, 175, 120];
        $lastIndex = count($columns) - 1;

        $nameRange = $this->sheetRange('Data Mentah', self::DATA_START_ROW, $rawLastRow, 2);
        $statusRange = $this->sheetRange('Data Mentah', self::DATA_START_ROW, $rawLastRow, 9);
        $provinceRange = $this->sheetRange('Data Mentah', self::DATA_START_ROW, $rawLastRow, 4);
        $cityRange = $this->sheetRange('Data Mentah', self::DATA_START_ROW, $rawLastRow, 5);
        $notesRange = $this->sheetRange('Data Mentah', self::DATA_START_ROW, $rawLastRow, 10);
        $leadTrendRange = $this->sheetRange('Tren', self::DATA_START_ROW, $trendLastRow, 3);
        $surveyTrendRange = $this->sheetRange('Tren', self::DATA_START_ROW, $trendLastRow, 4);
        $dealTrendRange = $this->sheetRange('Tren', self::DATA_START_ROW, $trendLastRow, 5);

        $surveyStatus = $this->escapeFormulaString($report['topPerformers']['status']['name'] ?? config('statuses.survey', 'Request Survey'));
        $configuredSurvey = $this->escapeFormulaString(config('statuses.survey', 'Request Survey'));
        $configuredDeal = $this->escapeFormulaString(config('statuses.deal', 'Selesai/Deal'));

        $rows = [
            $this->row([
                $this->cell('Executive Dashboard', 'sheetTitle', mergeAcross: $lastIndex),
            ], 30),
            $this->row([
                $this->cell(
                    sprintf(
                        '%s | %s | generated %s',
                        $report['periodLabel'] ?? '-',
                        $report['selectedAccountName'] ?? 'Semua Akun',
                        now()->format('d/m/Y H:i')
                    ),
                    'sheetSubtitle',
                    mergeAcross: $lastIndex
                ),
            ], 22),
            $this->blankRow(count($columns)),
            $this->row([
                $this->cell('Ringkasan Utama', 'sectionTitle', mergeAcross: $lastIndex),
            ]),
            $this->row([
                $this->cell('Total Lead', 'kpiLabel'),
                $this->formulaCell("=COUNTA($nameRange)", $report['totalLeads'] ?? 0, 'kpiValueNumber'),
                $this->cell('Total Survey', 'kpiLabel'),
                $this->formulaCell("=COUNTIF($statusRange,\"$configuredSurvey\")", $report['totalSurveys'] ?? 0, 'kpiValueNumber'),
                $this->cell('Total Deal', 'kpiLabel'),
                $this->formulaCell("=COUNTIF($statusRange,\"$configuredDeal\")", $report['totalDeals'] ?? 0, 'kpiValueNumber'),
            ], 24),
            $this->row([
                $this->cell('Konversi Survey', 'kpiLabel'),
                // Survey / Lead. This cell is in column 2 (same column as Total
                // Lead above, Total Survey is 2 columns to the right).
                $this->formulaCell('=IF(R[-1]C=0,0,R[-1]C[2]/R[-1]C)', ($report['conversionRate'] ?? 0) / 100, 'percentStrong'),
                $this->cell('Konversi Deal', 'kpiLabel'),
                // Deal / Lead. This cell is in column 4 (Total Lead is 2 columns
                // left, Total Deal is 2 columns right).
                $this->formulaCell('=IF(R[-1]C[-2]=0,0,R[-1]C[2]/R[-1]C[-2])', ($report['dealRate'] ?? 0) / 100, 'percentStrong'),
                $this->cell('Pertumbuhan vs Periode Lalu', 'kpiLabel'),
                $this->cell(($report['growthPercent'] ?? 0) / 100, 'percentStrong', 'Number'),
            ], 24),
            $this->blankRow(count($columns)),
            $this->row([
                $this->cell('Kualitas Data & Produktivitas', 'sectionTitle', mergeAcross: $lastIndex),
            ]),
            $this->row([
                $this->cell('Provinsi Terisi', 'metaLabel'),
                $this->formulaCell("=COUNTIF($provinceRange,\"<>\")", $report['dataQuality']['province_filled'] ?? 0, 'tableNumber'),
                $this->cell('Kota Terisi', 'metaLabel'),
                $this->formulaCell("=COUNTIF($cityRange,\"<>\")", $report['dataQuality']['city_filled'] ?? 0, 'tableNumber'),
                $this->cell('Catatan Terisi', 'metaLabel'),
                $this->formulaCell("=COUNTIF($notesRange,\"<>\")", $report['dataQuality']['notes_filled'] ?? 0, 'tableNumber'),
            ], 22),
            $this->row([
                $this->cell('Kelengkapan Lokasi', 'metaLabel'),
                $this->formulaCell(
                    "=IF(COUNTA($nameRange)=0,0,COUNTIFS($provinceRange,\"<>\",$cityRange,\"<>\")/COUNTA($nameRange))",
                    ($report['dataQuality']['location_completion_rate'] ?? 0) / 100,
                    'tablePercent'
                ),
                $this->cell('Rata-rata Lead per Hari Aktif', 'metaLabel'),
                $this->cell($report['summaryStats']['avg_per_active_day'] ?? 0, 'tableNumberDecimal', 'Number'),
                $this->cell('Rata-rata Lead per Hari', 'metaLabel'),
                $this->cell($report['summaryStats']['avg_per_calendar_day'] ?? 0, 'tableNumberDecimal', 'Number'),
            ], 22),
            $this->row([
                $this->cell('Periode Tertinggi', 'metaLabel'),
                $this->cell(($report['summaryStats']['peak_period_label'] ?? '-') . ' (' . ($report['summaryStats']['peak_period_total'] ?? 0) . ')', 'metaValue'),
                $this->cell('Total Lead (Tren)', 'metaLabel'),
                $this->formulaCell("=SUM($leadTrendRange)", $report['totalLeads'] ?? 0, 'tableNumber'),
                $this->cell('Total Survey (Tren)', 'metaLabel'),
                $this->formulaCell("=SUM($surveyTrendRange)", $report['totalSurveys'] ?? 0, 'tableNumber'),
            ], 22),
            $this->row([
                $this->cell('Total Deal (Tren)', 'metaLabel'),
                $this->formulaCell("=SUM($dealTrendRange)", $report['totalDeals'] ?? 0, 'tableNumber'),
                $this->cell('Admin Aktif', 'metaLabel'),
                $this->cell($report['dataQuality']['active_admins'] ?? 0, 'tableNumber', 'Number'),
                $this->cell('Telepon Ganda', 'metaLabel'),
                $this->cell($report['dataQuality']['duplicate_phone_rows'] ?? 0, 'tableNumber', 'Number'),
            ], 22),
            $this->blankRow(count($columns)),
            $this->row([
                $this->cell('Sorotan Utama', 'sectionTitle', mergeAcross: $lastIndex),
            ]),
            $this->row([
                $this->cell('Status Terbanyak', 'tableHeader'),
                $this->cell('Kebutuhan Terbanyak', 'tableHeader'),
                $this->cell('Provinsi Terbanyak', 'tableHeader'),
                $this->cell('Akun Terbaik', 'tableHeader'),
                $this->cell('Admin Terbaik', 'tableHeader', mergeAcross: 1),
            ], 22),
            $this->row([
                $this->cell(($report['topPerformers']['status']['name'] ?? '-') . ' | ' . (($report['topPerformers']['status']['count'] ?? 0)) . ' lead', 'tableCellWrap'),
                $this->cell(($report['topPerformers']['need']['name'] ?? '-') . ' | ' . (($report['topPerformers']['need']['count'] ?? 0)) . ' lead', 'tableCellWrap'),
                $this->cell(($report['topPerformers']['province']['name'] ?? '-') . ' | ' . (($report['topPerformers']['province']['percentage'] ?? 0)) . '%', 'tableCellWrap'),
                $this->cell(($report['topPerformers']['account']['name'] ?? '-') . ' | score ' . (($report['topPerformers']['account']['score'] ?? 0)), 'tableCellWrap'),
                $this->cell(($report['topPerformers']['admin']['name'] ?? '-') . ' | ' . (($report['topPerformers']['admin']['total'] ?? 0)) . ' lead', 'tableCellWrap', mergeAcross: 1),
            ], 36),
        ];

        return [
            'name' => 'Dashboard',
            'columns' => $columns,
            'rows' => $rows,
            'freeze_rows' => 4,
        ];
    }

    private function buildTrendSheet(Collection $trendSeries, array $report): array
    {
        $rows = $this->sheetIntroRows(
            'Tren',
            'Timeline volume lead, survey, dan deal per bucket periode.',
            8
        );

        $rows[] = $this->row([
            $this->cell('No', 'tableHeader'),
            $this->cell('Periode', 'tableHeader'),
            $this->cell('Lead', 'tableHeader'),
            $this->cell('Survey', 'tableHeader'),
            $this->cell('Deal', 'tableHeader'),
            $this->cell('Survey Rate', 'tableHeader'),
            $this->cell('Deal Rate', 'tableHeader'),
            $this->cell('Kumulatif Lead', 'tableHeader'),
        ], 24);

        $items = $trendSeries->values();

        if ($items->isEmpty()) {
            $rows[] = $this->row([
                $this->cell('Tidak ada data tren pada periode ini.', 'emptyState', mergeAcross: 7),
            ], 24);
        } else {
            foreach ($items as $index => $item) {
                $base = $index % 2 === 0 ? '' : 'Alt';
                $excelRow = self::DATA_START_ROW + $index;
                $rows[] = $this->row([
                    $this->cell($index + 1, 'tableCellCenter' . $base, 'Number'),
                    $this->cell($item['full_label'] ?? $item['label'] ?? '-', 'tableCell' . $base),
                    $this->cell($item['total'] ?? 0, 'tableNumber' . $base, 'Number'),
                    $this->cell($item['surveys'] ?? 0, 'tableNumber' . $base, 'Number'),
                    $this->cell($item['deals'] ?? 0, 'tableNumber' . $base, 'Number'),
                    $this->formulaCell(
                        '=IF(RC[-3]=0,0,RC[-2]/RC[-3])',
                        ($item['survey_rate'] ?? 0) / 100,
                        'tablePercent' . $base
                    ),
                    $this->formulaCell(
                        '=IF(RC[-4]=0,0,RC[-2]/RC[-4])',
                        ($item['deal_rate'] ?? 0) / 100,
                        'tablePercent' . $base
                    ),
                    $this->formulaCell(
                        sprintf('=SUM(R%dC3:R%dC3)', self::DATA_START_ROW, $excelRow),
                        $items->take($index + 1)->sum('total'),
                        'tableNumber' . $base
                    ),
                ], 22);
            }
        }

        return [
            'name' => 'Tren',
            'columns' => [50, 165, 80, 80, 80, 85, 85, 95],
            'rows' => $rows,
            'freeze_rows' => 4,
        ];
    }

    private function buildQualitySheet(array $report, int $rawLastRow): array
    {
        $rows = $this->sheetIntroRows(
            'Kualitas Data',
            'Audit cepat terhadap kelengkapan data dan validitas input.',
            4
        );

        $nameRange = $this->sheetRange('Data Mentah', self::DATA_START_ROW, $rawLastRow, 2);
        $provinceRange = $this->sheetRange('Data Mentah', self::DATA_START_ROW, $rawLastRow, 4);
        $cityRange = $this->sheetRange('Data Mentah', self::DATA_START_ROW, $rawLastRow, 5);
        $notesRange = $this->sheetRange('Data Mentah', self::DATA_START_ROW, $rawLastRow, 10);

        $rows[] = $this->row([
            $this->cell('Metrik', 'tableHeader'),
            $this->cell('Jumlah', 'tableHeader'),
            $this->cell('Persentase', 'tableHeader'),
            $this->cell('Keterangan', 'tableHeader'),
        ], 24);

        $qualityRows = [
            [
                'label' => 'Provinsi terisi',
                'value_cell' => $this->formulaCell("=COUNTIF($provinceRange,\"<>\")", $report['dataQuality']['province_filled'] ?? 0, 'tableNumber'),
                'percentage_cell' => $this->formulaCell("=IF(COUNTA($nameRange)=0,0,COUNTIF($provinceRange,\"<>\")/COUNTA($nameRange))", ($report['dataQuality']['province_completion_rate'] ?? 0) / 100, 'tablePercent'),
                'note' => 'Target tinggi untuk analisa wilayah',
            ],
            [
                'label' => 'Kota terisi',
                'value_cell' => $this->formulaCell("=COUNTIF($cityRange,\"<>\")", $report['dataQuality']['city_filled'] ?? 0, 'tableNumber'),
                'percentage_cell' => $this->formulaCell("=IF(COUNTA($nameRange)=0,0,COUNTIF($cityRange,\"<>\")/COUNTA($nameRange))", ($report['dataQuality']['city_completion_rate'] ?? 0) / 100, 'tablePercent'),
                'note' => 'Penting untuk pemetaan market granular',
            ],
            [
                'label' => 'Catatan terisi',
                'value_cell' => $this->formulaCell("=COUNTIF($notesRange,\"<>\")", $report['dataQuality']['notes_filled'] ?? 0, 'tableNumber'),
                'percentage_cell' => $this->formulaCell("=IF(COUNTA($nameRange)=0,0,COUNTIF($notesRange,\"<>\")/COUNTA($nameRange))", ($report['dataQuality']['notes_completion_rate'] ?? 0) / 100, 'tablePercent'),
                'note' => 'Meningkatkan konteks follow-up sales',
            ],
            [
                'label' => 'Lokasi lengkap',
                'value_cell' => $this->formulaCell("=COUNTIFS($provinceRange,\"<>\",$cityRange,\"<>\")", $report['dataQuality']['location_complete'] ?? 0, 'tableNumber'),
                'percentage_cell' => $this->formulaCell("=IF(COUNTA($nameRange)=0,0,COUNTIFS($provinceRange,\"<>\",$cityRange,\"<>\")/COUNTA($nameRange))", ($report['dataQuality']['location_completion_rate'] ?? 0) / 100, 'tablePercent'),
                'note' => 'Siap dipakai untuk reporting wilayah',
            ],
            [
                'label' => 'Nomor telepon ganda',
                'value_cell' => $this->cell($report['dataQuality']['duplicate_phone_rows'] ?? 0, 'tableNumber', 'Number'),
                'percentage_cell' => $this->cell('-', 'tableCellCenter'),
                'note' => 'Perlu audit jika nilainya tinggi',
            ],
            [
                'label' => 'Provinsi unik',
                'value_cell' => $this->cell($report['dataQuality']['unique_provinces'] ?? 0, 'tableNumber', 'Number'),
                'percentage_cell' => $this->cell('-', 'tableCellCenter'),
                'note' => 'Semakin banyak, jangkauan semakin luas',
            ],
            [
                'label' => 'Kota unik',
                'value_cell' => $this->cell($report['dataQuality']['unique_cities'] ?? 0, 'tableNumber', 'Number'),
                'percentage_cell' => $this->cell('-', 'tableCellCenter'),
                'note' => 'Indikator sebaran demand',
            ],
            [
                'label' => 'Latest update',
                'value_cell' => $this->cell($report['dataQuality']['latest_update'] ?? '-', 'tableCell'),
                'percentage_cell' => $this->cell('-', 'tableCellCenter'),
                'note' => 'Kontrol freshness data',
            ],
        ];

        foreach ($qualityRows as $index => $item) {
            $base = $index % 2 === 0 ? '' : 'Alt';
            $rows[] = $this->row([
                $this->cell($item['label'], 'tableCell' . $base),
                $this->restyleCell($item['value_cell'], $base),
                $this->restyleCell($item['percentage_cell'], $base),
                $this->cell($item['note'], 'tableCell' . $base),
            ], 22);
        }

        return [
            'name' => 'Kualitas Data',
            'columns' => [180, 120, 95, 240],
            'rows' => $rows,
            'freeze_rows' => 4,
        ];
    }

    private function buildMetricSheet(
        string $sheetName,
        string $subtitle,
        Collection $items,
        array $columns,
        bool $withColor = false
    ): array {
        $rows = $this->sheetIntroRows($sheetName, $subtitle, count($columns));
        $total = max($items->sum('count'), 1);

        $headers = [
            $this->cell('No', 'tableHeader'),
            $this->cell($sheetName === 'Status' ? 'Status' : 'Kategori', 'tableHeader'),
            $this->cell('Jumlah', 'tableHeader'),
            $this->cell('Persentase', 'tableHeader'),
            $this->cell('Komposisi', 'tableHeader'),
        ];

        if ($withColor) {
            $headers[] = $this->cell('Warna', 'tableHeader');
        }

        $rows[] = $this->row($headers, 24);

        if ($items->isEmpty()) {
            $rows[] = $this->row([
                $this->cell('Tidak ada data pada periode ini.', 'emptyState', mergeAcross: count($columns) - 1),
            ], 24);
        } else {
            foreach ($items->values() as $index => $item) {
                $base = $index % 2 === 0 ? '' : 'Alt';
                $shareText = str_repeat('■', max((int) round((($item['count'] ?? 0) / $total) * 10), 1));
                $row = [
                    $this->cell($index + 1, 'tableCellCenter' . $base, 'Number'),
                    $this->cell($item['name'] ?? '-', 'tableCell' . $base),
                    $this->cell($item['count'] ?? 0, 'tableNumber' . $base, 'Number'),
                    $this->cell(($item['count'] ?? 0) / $total, 'tablePercent' . $base, 'Number'),
                    $this->cell($shareText, 'tableCellCenter' . $base),
                ];

                if ($withColor) {
                    $row[] = $this->cell($item['color'] ?? '-', 'tableCell' . $base);
                }

                $rows[] = $this->row($row, 22);
            }
        }

        return [
            'name' => $sheetName,
            'columns' => $columns,
            'rows' => $rows,
            'freeze_rows' => 4,
        ];
    }

    /**
     * Narrative sheet: executive KPIs, the conversion funnel, and the
     * auto-generated insights/recommendations — so the Excel carries the
     * analysis, not just raw tables.
     */
    private function buildAnalysisSheet(array $report): array
    {
        $columns = [55, 320, 150, 150];
        $lastIndex = count($columns) - 1;

        $funnel = $report['funnel'] ?? [];
        $insights = $report['insights'] ?? collect();
        if (! $insights instanceof Collection) {
            $insights = collect($insights);
        }

        $totalLeads = (int) ($report['totalLeads'] ?? 0);
        $totalSurveys = (int) ($report['totalSurveys'] ?? 0);
        $totalDeals = (int) ($report['totalDeals'] ?? 0);
        $prevLeads = (int) ($report['previousTotalLeads'] ?? 0);
        $growthDelta = (int) ($report['growthDelta'] ?? ($totalLeads - $prevLeads));
        $growthPercent = (float) ($report['growthPercent'] ?? 0);
        $conversionRate = (float) ($report['conversionRate'] ?? 0);
        $surveyRate = (float) ($report['requestSurveyRate'] ?? 0);

        $rows = $this->sheetIntroRows(
            'Analisis & Insight',
            'Ringkasan eksekutif, funnel konversi, dan rekomendasi otomatis untuk '
                . ($report['periodLabel'] ?? 'periode terpilih') . '.',
            count($columns)
        );

        // ── Ringkasan Eksekutif ──────────────────────────────────────────
        $rows[] = $this->row([
            $this->cell('Ringkasan Eksekutif', 'sectionTitle', mergeAcross: $lastIndex),
        ], 26);
        $rows[] = $this->row([
            $this->cell('Metrik', 'tableHeader'),
            $this->cell('Nilai', 'tableHeader'),
            $this->cell('Keterangan', 'tableHeader', mergeAcross: 1),
        ], 24);

        $kpis = [
            ['Total Leads', $totalLeads, 'tableNumber', 'Number', 'Periode sebelumnya: ' . number_format($prevLeads)],
            ['Total Survey', $totalSurveys, 'tableNumber', 'Number', 'Lead yang masuk tahap survey'],
            ['Total Deal', $totalDeals, 'tableNumber', 'Number', 'Lead yang berhasil closing/deal'],
            ['Conversion Rate (Deal)', $conversionRate / 100, 'tablePercent', 'Number', 'Total deal dibagi total leads'],
            ['Survey Rate', $surveyRate / 100, 'tablePercent', 'Number', 'Total survey dibagi total leads'],
            ['Deal dari Survey', (float) ($funnel['deal_from_survey_rate'] ?? 0) / 100, 'tablePercent', 'Number', 'Total deal dibagi total survey'],
            ['Pertumbuhan Leads', $growthPercent / 100, 'tablePercent', 'Number', 'Selisih ' . ($growthDelta >= 0 ? '+' : '') . number_format($growthDelta) . ' lead vs periode sebelumnya'],
        ];
        foreach ($kpis as $i => [$label, $value, $valStyle, $valType, $note]) {
            $base = $i % 2 === 0 ? '' : 'Alt';
            $rows[] = $this->row([
                $this->cell($label, 'tableCell' . $base),
                $this->cell($value, $valStyle . $base, $valType),
                $this->cell($note, 'tableCell' . $base, mergeAcross: 1),
            ], 22);
        }

        $rows[] = $this->blankRow(count($columns));

        // ── Funnel Konversi ──────────────────────────────────────────────
        $rows[] = $this->row([
            $this->cell('Funnel Konversi', 'sectionTitle', mergeAcross: $lastIndex),
        ], 26);
        $rows[] = $this->row([
            $this->cell('Tahap', 'tableHeader'),
            $this->cell('Jumlah', 'tableHeader'),
            $this->cell('Konversi dari Lead', 'tableHeader'),
            $this->cell('Catatan', 'tableHeader'),
        ], 24);

        $funnelRows = [
            ['Lead Masuk', (int) ($funnel['leads'] ?? $totalLeads), 1.0, 'Seluruh lead yang masuk pada periode ini'],
            ['Request Survey', (int) ($funnel['surveys'] ?? $totalSurveys), (float) ($funnel['survey_rate'] ?? 0) / 100, 'Lead yang berlanjut ke tahap survey'],
            ['Deal / Closing', (int) ($funnel['deals'] ?? $totalDeals), (float) ($funnel['deal_rate'] ?? 0) / 100, 'Konversi dari survey: ' . number_format((float) ($funnel['deal_from_survey_rate'] ?? 0), 1) . '%'],
        ];
        foreach ($funnelRows as $i => [$stage, $count, $rate, $note]) {
            $base = $i % 2 === 0 ? '' : 'Alt';
            $rows[] = $this->row([
                $this->cell($stage, 'tableCell' . $base),
                $this->cell($count, 'tableNumber' . $base, 'Number'),
                $this->cell($rate, 'tablePercent' . $base, 'Number'),
                $this->cell($note, 'tableCell' . $base),
            ], 22);
        }

        $rows[] = $this->blankRow(count($columns));

        // ── Insight & Rekomendasi ────────────────────────────────────────
        $rows[] = $this->row([
            $this->cell('Insight & Rekomendasi', 'sectionTitle', mergeAcross: $lastIndex),
        ], 26);
        $rows[] = $this->row([
            $this->cell('No', 'tableHeader'),
            $this->cell('Insight', 'tableHeader', mergeAcross: 2),
        ], 24);

        if ($insights->isEmpty()) {
            $rows[] = $this->row([
                $this->cell('Belum ada insight untuk periode ini.', 'emptyState', mergeAcross: $lastIndex),
            ], 24);
        } else {
            foreach ($insights->values() as $index => $insight) {
                $base = $index % 2 === 0 ? '' : 'Alt';
                $text = is_array($insight) ? ($insight['html'] ?? '') : (string) $insight;
                $text = trim(html_entity_decode(strip_tags((string) $text), ENT_QUOTES, 'UTF-8'));
                $rows[] = $this->row([
                    $this->cell($index + 1, 'tableCellCenter' . $base, 'Number'),
                    $this->cell($text, 'tableCellWrap' . $base, mergeAcross: 2),
                ], 30);
            }
        }

        return [
            'name' => 'Analisis & Insight',
            'columns' => $columns,
            'rows' => $rows,
            'freeze_rows' => 4,
        ];
    }

    private function buildRegionSheet(array $report): array
    {
        $rows = $this->sheetIntroRows(
            'Wilayah',
            'Distribusi provinsi, kota, dan segmen Jawa Barat.',
            5
        );

        $sections = [
            ['title' => 'Top Provinsi', 'label' => 'Provinsi', 'items' => $report['provinceDistribution'] ?? collect(), 'percentage' => true],
            ['title' => 'Top Kota / Kabupaten', 'label' => 'Kota / Kabupaten', 'items' => $report['cityDistribution'] ?? collect(), 'percentage' => true],
            ['title' => 'Segmen Jawa Barat', 'label' => 'Segmen', 'items' => $report['westJavaSegmentDistribution'] ?? collect(), 'percentage' => false],
        ];

        foreach ($sections as $sectionIndex => $section) {
            if ($sectionIndex > 0) {
                $rows[] = $this->blankRow(5);
            }

            $rows[] = $this->row([
                $this->cell($section['title'], 'sectionTitle', mergeAcross: 4),
            ]);
            $rows[] = $this->row([
                $this->cell('No', 'tableHeader'),
                $this->cell($section['label'], 'tableHeader'),
                $this->cell('Jumlah', 'tableHeader'),
                $this->cell($section['percentage'] ? 'Persentase' : 'Warna', 'tableHeader'),
                $this->cell('Catatan', 'tableHeader'),
            ], 24);

            $items = collect($section['items']);
            $total = max($items->sum('count'), 1);

            if ($items->isEmpty()) {
                $rows[] = $this->row([
                    $this->cell('Tidak ada data wilayah pada periode ini.', 'emptyState', mergeAcross: 4),
                ], 24);
                continue;
            }

            foreach ($items->values() as $index => $item) {
                $base = $index % 2 === 0 ? '' : 'Alt';
                $rows[] = $this->row([
                    $this->cell($index + 1, 'tableCellCenter' . $base, 'Number'),
                    $this->cell($item['name'] ?? '-', 'tableCell' . $base),
                    $this->cell($item['count'] ?? 0, 'tableNumber' . $base, 'Number'),
                    $section['percentage']
                        ? $this->cell(($item['percentage'] ?? 0) / 100, 'tablePercent' . $base, 'Number')
                        : $this->cell($item['color'] ?? '-', 'tableCell' . $base),
                    $this->cell(
                        $section['percentage']
                            ? 'Kontribusi terhadap total distribusi'
                            : round((($item['count'] ?? 0) / $total) * 100, 1) . '% dari total segmen',
                        'tableCell' . $base
                    ),
                ], 22);
            }
        }

        return [
            'name' => 'Wilayah',
            'columns' => [50, 220, 90, 90, 180],
            'rows' => $rows,
            'freeze_rows' => 4,
        ];
    }

    private function buildRankingSheet(array $report): array
    {
        $rows = $this->sheetIntroRows(
            'Ranking',
            'Peringkat performa akun dan admin pada periode aktif.',
            6
        );

        $rows[] = $this->row([
            $this->cell('Ranking Akun', 'sectionTitle', mergeAcross: 5),
        ]);
        $rows[] = $this->row([
            $this->cell('No', 'tableHeader'),
            $this->cell('Akun', 'tableHeader'),
            $this->cell('Lead', 'tableHeader'),
            $this->cell('Survey', 'tableHeader'),
            $this->cell('Deal', 'tableHeader'),
            $this->cell('Skor', 'tableHeader'),
        ], 24);

        $accounts = $report['accountRanking'] ?? collect();
        if ($accounts->isEmpty()) {
            $rows[] = $this->row([
                $this->cell('Tidak ada data ranking akun.', 'emptyState', mergeAcross: 5),
            ], 24);
        } else {
            foreach ($accounts->values() as $index => $item) {
                $base = $index % 2 === 0 ? '' : 'Alt';
                $rows[] = $this->row([
                    $this->cell($index + 1, 'tableCellCenter' . $base, 'Number'),
                    $this->cell($item['name'] ?? '-', 'tableCell' . $base),
                    $this->cell($item['total'] ?? 0, 'tableNumber' . $base, 'Number'),
                    $this->cell($item['surveys'] ?? 0, 'tableNumber' . $base, 'Number'),
                    $this->cell($item['deals'] ?? 0, 'tableNumber' . $base, 'Number'),
                    $this->cell($item['score'] ?? 0, 'tableNumberDecimal' . $base, 'Number'),
                ], 22);
            }
        }

        $rows[] = $this->blankRow(6);
        $rows[] = $this->row([
            $this->cell('Ranking Admin', 'sectionTitle', mergeAcross: 5),
        ]);
        $rows[] = $this->row([
            $this->cell('No', 'tableHeader'),
            $this->cell('Admin', 'tableHeader'),
            $this->cell('Akun', 'tableHeader'),
            $this->cell('Total Lead', 'tableHeader'),
            $this->cell('Porsi', 'tableHeader'),
            $this->cell('Catatan', 'tableHeader'),
        ], 24);

        $admins = $report['adminRanking'] ?? collect();
        $totalAdminLead = max($admins->sum('total'), 1);

        if ($admins->isEmpty()) {
            $rows[] = $this->row([
                $this->cell('Tidak ada data ranking admin.', 'emptyState', mergeAcross: 5),
            ], 24);
        } else {
            foreach ($admins->values() as $index => $item) {
                $base = $index % 2 === 0 ? '' : 'Alt';
                $rows[] = $this->row([
                    $this->cell($index + 1, 'tableCellCenter' . $base, 'Number'),
                    $this->cell($item['name'] ?? '-', 'tableCell' . $base),
                    $this->cell($item['account'] ?? '-', 'tableCell' . $base),
                    $this->cell($item['total'] ?? 0, 'tableNumber' . $base, 'Number'),
                    $this->cell(($item['total'] ?? 0) / $totalAdminLead, 'tablePercent' . $base, 'Number'),
                    $this->cell('Volume input periode aktif', 'tableCell' . $base),
                ], 22);
            }
        }

        return [
            'name' => 'Ranking',
            'columns' => [50, 180, 160, 90, 85, 170],
            'rows' => $rows,
            'freeze_rows' => 4,
        ];
    }

    private function buildRawDataSheet(Collection $rows, array $report): array
    {
        $columns = [110, 170, 105, 100, 110, 110, 125, 125, 120, 220, 92, 60, 70, 60, 60, 120, 92, 85, 220];
        $sheetRows = $this->sheetIntroRows(
            'Data Mentah',
            sprintf(
                'Lampiran lengkap data konsultasi untuk %s | %s.',
                $report['selectedAccountName'] ?? 'Semua Akun',
                $report['periodLabel'] ?? '-'
            ),
            count($columns)
        );

        $sheetRows[] = $this->row([
            $this->cell('ID Konsultasi', 'tableHeader'),
            $this->cell('Nama Klien', 'tableHeader'),
            $this->cell('No Telepon', 'tableHeader'),
            $this->cell('Provinsi', 'tableHeader'),
            $this->cell('Kota', 'tableHeader'),
            $this->cell('Kecamatan', 'tableHeader'),
            $this->cell('Akun', 'tableHeader'),
            $this->cell('Kebutuhan', 'tableHeader'),
            $this->cell('Status', 'tableHeader'),
            $this->cell('Catatan', 'tableHeader'),
            $this->cell('Tgl Konsultasi', 'tableHeader'),
            $this->cell('Tahun', 'tableHeader'),
            $this->cell('Bulan', 'tableHeader'),
            $this->cell('Minggu', 'tableHeader'),
            $this->cell('Kuartal', 'tableHeader'),
            $this->cell('Dibuat Oleh', 'tableHeader'),
            $this->cell('Terakhir Diubah', 'tableHeader'),
            $this->cell('Umur (hari)', 'tableHeader'),
            $this->cell('Detail Produk', 'tableHeader'),
        ], 26);

        $items = $rows->values();

        if ($items->isEmpty()) {
            $items = collect([[
                'consultation_id' => '',
                'client_name' => '',
                'phone' => '',
                'province' => '',
                'city' => '',
                'account' => '',
                'need' => '',
                'status' => '',
                'notes' => '',
                'consultation_date_excel' => null,
                'creator' => '',
                'updated_at_excel' => null,
            ]]);
        }

        foreach ($items as $index => $row) {
            $base = $index % 2 === 0 ? '' : 'Alt';
            $sheetRows[] = $this->row([
                $this->cell($row['consultation_id'] ?? '', 'tableCell' . $base),
                $this->cell($row['client_name'] ?? '', 'tableCell' . $base),
                $this->cell($row['phone'] ?? '', 'tableCell' . $base),
                $this->cell($row['province'] ?? '', 'tableCell' . $base),
                $this->cell($row['city'] ?? '', 'tableCell' . $base),
                $this->cell($row['district'] ?? '', 'tableCell' . $base),
                $this->cell($row['account'] ?? '', 'tableCell' . $base),
                $this->cell($row['need'] ?? '', 'tableCell' . $base),
                $this->cell($row['status'] ?? '', 'tableCell' . $base),
                $this->cell($row['notes'] ?? '', 'tableCellWrap' . $base),
                $row['consultation_date_excel']
                    ? $this->cell($row['consultation_date_excel'], 'dateCell' . $base, 'DateTime')
                    : $this->cell('', 'tableCellCenter' . $base),
                $this->formulaCell('=IF(ISBLANK(RC[-1]),"",YEAR(RC[-1]))', $row['consultation_date_excel'] ? (int) substr($row['consultation_date_excel'], 0, 4) : '', 'tableNumber' . $base, 'Number'),
                $this->formulaCell('=IF(ISBLANK(RC[-2]),"",MONTH(RC[-2]))', $row['consultation_date_excel'] ? (int) substr($row['consultation_date_excel'], 5, 2) : '', 'tableNumber' . $base, 'Number'),
                $this->formulaCell('=IF(ISBLANK(RC[-3]),"",WEEKNUM(RC[-3],2))', '', 'tableNumber' . $base, 'Number'),
                $this->formulaCell('=IF(ISBLANK(RC[-4]),"",ROUNDUP(MONTH(RC[-4])/3,0))', '', 'tableNumber' . $base, 'Number'),
                $this->cell($row['creator'] ?? '', 'tableCell' . $base),
                $row['updated_at_excel']
                    ? $this->cell($row['updated_at_excel'], 'dateTimeCell' . $base, 'DateTime')
                    : $this->cell('', 'tableCellCenter' . $base),
                $this->formulaCell('=IF(ISBLANK(RC[-7]),"",TODAY()-RC[-7])', '', 'tableNumber' . $base, 'Number'),
                $this->cell($row['product_details'] ?? '', 'tableCellWrap' . $base),
            ], 34);
        }

        return [
            'name' => 'Data Mentah',
            'columns' => $columns,
            'rows' => $sheetRows,
            'freeze_rows' => 4,
        ];
    }

    private function sheetIntroRows(string $title, string $subtitle, int $columnCount): array
    {
        $lastIndex = $columnCount - 1;

        return [
            $this->row([
                $this->cell($title, 'sheetTitle', mergeAcross: $lastIndex),
            ], 30),
            $this->row([
                $this->cell($subtitle, 'sheetSubtitle', mergeAcross: $lastIndex),
            ], 22),
            $this->blankRow($columnCount),
        ];
    }

    private function row(array $cells, ?int $height = null): array
    {
        return ['cells' => $cells, 'height' => $height];
    }

    private function blankRow(int $columnCount): array
    {
        return $this->row([
            $this->cell('', 'blank', mergeAcross: $columnCount - 1),
        ], 10);
    }

    private function cell(
        mixed $value,
        ?string $style = null,
        string $type = 'String',
        ?int $mergeAcross = null
    ): array {
        return [
            'value' => $value,
            'style' => $style,
            'type' => $type,
            'merge_across' => $mergeAcross,
        ];
    }

    private function formulaCell(
        string $formula,
        mixed $value,
        ?string $style = null,
        string $type = 'Number',
        ?int $mergeAcross = null
    ): array {
        return [
            'value' => $value,
            'style' => $style,
            'type' => $type,
            'formula' => $formula,
            'merge_across' => $mergeAcross,
        ];
    }

    private function restyleCell(array $cell, string $base): array
    {
        $style = $cell['style'] ?? 'tableCell';

        if ($base === 'Alt') {
            $style = str_ends_with($style, 'Alt') ? $style : $style . 'Alt';
        }

        $cell['style'] = $style;

        return $cell;
    }

    private function buildCell(array $cell): string
    {
        $attributes = [];

        if (! empty($cell['style'])) {
            $attributes[] = sprintf('ss:StyleID="%s"', $cell['style']);
        }

        if (($cell['merge_across'] ?? null) !== null) {
            $attributes[] = sprintf('ss:MergeAcross="%d"', (int) $cell['merge_across']);
        }

        if (! empty($cell['formula'])) {
            $attributes[] = sprintf(
                'ss:Formula="%s"',
                htmlspecialchars((string) $cell['formula'], ENT_XML1 | ENT_COMPAT, 'UTF-8')
            );
        }

        $type = $cell['type'] ?? 'String';
        $value = $cell['value'] ?? '';

        $data = sprintf(
            '<Data ss:Type="%s">%s</Data>',
            $type,
            htmlspecialchars((string) $value, ENT_XML1 | ENT_COMPAT, 'UTF-8')
        );

        return sprintf('<Cell %s>%s</Cell>', implode(' ', $attributes), $data);
    }

    private function worksheetOptionsXml(int $freezeRows): string
    {
        return '<WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">'
            . '<FreezePanes/>'
            . '<FrozenNoSplit/>'
            . sprintf('<SplitHorizontal>%d</SplitHorizontal>', max(1, $freezeRows))
            . sprintf('<TopRowBottomPane>%d</TopRowBottomPane>', max(1, $freezeRows))
            . '<ActivePane>2</ActivePane>'
            . '<ProtectObjects>False</ProtectObjects>'
            . '<ProtectScenarios>False</ProtectScenarios>'
            . '</WorksheetOptions>';
    }

    private function sheetRange(string $sheetName, int $startRow, int $endRow, int $column): string
    {
        return sprintf(
            '%s!R%dC%d:R%dC%d',
            $this->sheetNameFormula($sheetName),
            $startRow,
            $column,
            $endRow,
            $column
        );
    }

    private function sheetNameFormula(string $sheetName): string
    {
        return "'" . str_replace("'", "''", $sheetName) . "'";
    }

    private function escapeFormulaString(string $value): string
    {
        return str_replace('"', '""', $value);
    }

    private function escapeSheetName(string $name): string
    {
        $normalized = mb_substr(preg_replace('/[\\\\\\/?*\\[\\]:]/', '-', $name), 0, 31);

        return htmlspecialchars($normalized, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    private function stylesXml(): string
    {
        // Shared "all borders" block applied to every table/data style so the
        // grid is visible on all sheets.
        $b = '<Borders>'
            . '<Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#94A3B8"/>'
            . '<Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#94A3B8"/>'
            . '<Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#94A3B8"/>'
            . '<Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#94A3B8"/>'
            . '</Borders>';

        return '<Styles>'
            . '<Style ss:ID="Default" ss:Name="Normal">'
            . '<Alignment ss:Vertical="Center"/>'
            . $b
            . '<Font ss:FontName="Calibri" ss:Size="11" ss:Color="#0F172A"/>'
            . '<Interior/>'
            . '<NumberFormat/>'
            . '<Protection/>'
            . '</Style>'
            . '<Style ss:ID="sheetTitle">' . $b . '<Font ss:Bold="1" ss:Size="20" ss:Color="#0F172A"/><Interior ss:Color="#FEF3C7" ss:Pattern="Solid"/></Style>'
            . '<Style ss:ID="sheetSubtitle"><Alignment ss:WrapText="1"/>' . $b . '<Font ss:Size="11" ss:Color="#92400E"/><Interior ss:Color="#FFFBEB" ss:Pattern="Solid"/></Style>'
            . '<Style ss:ID="sectionTitle"><Alignment ss:Vertical="Center"/>' . $b . '<Font ss:Bold="1" ss:Size="12.5" ss:Color="#FFFFFF"/><Interior ss:Color="#B45309" ss:Pattern="Solid"/></Style>'
            . '<Style ss:ID="metaLabel">' . $b . '<Font ss:Bold="1" ss:Color="#334155"/><Interior ss:Color="#F1F5F9" ss:Pattern="Solid"/></Style>'
            . '<Style ss:ID="metaValue"><Alignment ss:WrapText="1"/>' . $b . '<Interior ss:Color="#FFFFFF" ss:Pattern="Solid"/></Style>'
            . '<Style ss:ID="kpiLabel">' . $b . '<Font ss:Bold="1" ss:Color="#334155"/><Interior ss:Color="#FFF7ED" ss:Pattern="Solid"/></Style>'
            . '<Style ss:ID="kpiValueNumber"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/>' . $b . '<Font ss:Bold="1" ss:Size="15" ss:Color="#B45309"/><Interior ss:Color="#FFFFFF" ss:Pattern="Solid"/><NumberFormat ss:Format="#,##0"/></Style>'
            . '<Style ss:ID="percentStrong"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/>' . $b . '<Font ss:Bold="1" ss:Size="15" ss:Color="#047857"/><Interior ss:Color="#FFFFFF" ss:Pattern="Solid"/><NumberFormat ss:Format="0.0%"/></Style>'
            . '<Style ss:ID="tableHeader"><Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>' . $b . '<Font ss:Bold="1" ss:Size="11" ss:Color="#FFFFFF"/><Interior ss:Color="#1E293B" ss:Pattern="Solid"/></Style>'
            . '<Style ss:ID="tableCell"><Alignment ss:Vertical="Top" ss:WrapText="1"/>' . $b . '<Interior ss:Color="#FFFFFF" ss:Pattern="Solid"/></Style>'
            . '<Style ss:ID="tableCellAlt"><Alignment ss:Vertical="Top" ss:WrapText="1"/>' . $b . '<Interior ss:Color="#F8FAFC" ss:Pattern="Solid"/></Style>'
            . '<Style ss:ID="tableCellWrap"><Alignment ss:Vertical="Top" ss:WrapText="1"/>' . $b . '<Interior ss:Color="#FFFFFF" ss:Pattern="Solid"/></Style>'
            . '<Style ss:ID="tableCellWrapAlt"><Alignment ss:Vertical="Top" ss:WrapText="1"/>' . $b . '<Interior ss:Color="#F8FAFC" ss:Pattern="Solid"/></Style>'
            . '<Style ss:ID="tableCellCenter"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/>' . $b . '<Interior ss:Color="#FFFFFF" ss:Pattern="Solid"/></Style>'
            . '<Style ss:ID="tableCellCenterAlt"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/>' . $b . '<Interior ss:Color="#F8FAFC" ss:Pattern="Solid"/></Style>'
            . '<Style ss:ID="tableNumber"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/>' . $b . '<Interior ss:Color="#FFFFFF" ss:Pattern="Solid"/><NumberFormat ss:Format="#,##0"/></Style>'
            . '<Style ss:ID="tableNumberAlt"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/>' . $b . '<Interior ss:Color="#F8FAFC" ss:Pattern="Solid"/><NumberFormat ss:Format="#,##0"/></Style>'
            . '<Style ss:ID="tableNumberDecimal"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/>' . $b . '<Interior ss:Color="#FFFFFF" ss:Pattern="Solid"/><NumberFormat ss:Format="#,##0.0"/></Style>'
            . '<Style ss:ID="tableNumberDecimalAlt"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/>' . $b . '<Interior ss:Color="#F8FAFC" ss:Pattern="Solid"/><NumberFormat ss:Format="#,##0.0"/></Style>'
            . '<Style ss:ID="tablePercent"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/>' . $b . '<Interior ss:Color="#FFFFFF" ss:Pattern="Solid"/><NumberFormat ss:Format="0.0%"/></Style>'
            . '<Style ss:ID="tablePercentAlt"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/>' . $b . '<Interior ss:Color="#F8FAFC" ss:Pattern="Solid"/><NumberFormat ss:Format="0.0%"/></Style>'
            . '<Style ss:ID="dateCell"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/>' . $b . '<Interior ss:Color="#FFFFFF" ss:Pattern="Solid"/><NumberFormat ss:Format="dd/mm/yyyy"/></Style>'
            . '<Style ss:ID="dateCellAlt"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/>' . $b . '<Interior ss:Color="#F8FAFC" ss:Pattern="Solid"/><NumberFormat ss:Format="dd/mm/yyyy"/></Style>'
            . '<Style ss:ID="dateTimeCell"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/>' . $b . '<Interior ss:Color="#FFFFFF" ss:Pattern="Solid"/><NumberFormat ss:Format="dd/mm/yyyy hh:mm"/></Style>'
            . '<Style ss:ID="dateTimeCellAlt"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/>' . $b . '<Interior ss:Color="#F8FAFC" ss:Pattern="Solid"/><NumberFormat ss:Format="dd/mm/yyyy hh:mm"/></Style>'
            . '<Style ss:ID="emptyState"><Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>' . $b . '<Font ss:Italic="1" ss:Color="#64748B"/><Interior ss:Color="#F8FAFC" ss:Pattern="Solid"/></Style>'
            . '<Style ss:ID="blank"><Interior ss:Color="#FFFFFF" ss:Pattern="Solid"/></Style>'
            . '</Styles>';
    }
}
