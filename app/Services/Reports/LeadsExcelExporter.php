<?php

namespace App\Services\Reports;

use App\Models\Account;
use App\Models\NeedsCategory;
use App\Models\StatusCategory;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LeadsExcelExporter
{
    private const KLASEMEN_COLUMNS = [
        34, 255, 90, 90, 90, 90, 90, 105, 105, 105, 105,
    ];

    private const SURVEYOR_COLUMNS = [
        28, 180,
        70, 48, 48,
        70, 48, 48,
        70, 48, 48,
        80, 48, 48,
        80, 48, 48,
        80, 48, 48,
        80, 48, 48,
    ];

    private const DATA_LEADS_COLUMNS = [
        28, 132, 125, 151, 169, 145, 145, 145, 145, 131, 143, 272, 143, 167,
    ];

    private const ANALYSIS_COLUMNS = [213, 372, 143, 213];

    private const DATA_LEADS_TEMPLATE_ROWS = 302;

    private const LEAD_STATUS_OPTIONS = [
        'Hanya Tanya Tanya',
        'Request Survey',
        'Kendala Anggaran',
        'Tidak Ada Respon',
        'Selesai/Deal',
        'Masih konsultasi',
    ];

    private const METRIC_KEYS = [
        'interaction',
        'remaining_consultation',
        'survey',
        'hold',
        'cancel',
        'deal_survey_current',
        'deal_survey_previous',
        'project_deal_current',
        'project_deal_next',
    ];

    private const SURVEYOR_GROUPS = [
        ['key' => 'survey', 'label' => 'SURVEY', 'header' => 'headBlue', 'sub' => 'subHeadBlue', 'total' => 'totalBlue'],
        ['key' => 'hold', 'label' => 'HOLD', 'header' => 'headPeach', 'sub' => 'subHeadPeach', 'total' => 'totalPeach'],
        ['key' => 'cancel', 'label' => 'CANCEL', 'header' => 'headOrange', 'sub' => 'subHeadOrange', 'total' => 'totalOrange'],
        ['key' => 'deal_survey_current', 'label' => "DEAL, SURVEY\nPERIODE INI", 'header' => 'headLightGreen', 'sub' => 'subHeadLightGreen', 'total' => 'totalLightGreen'],
        ['key' => 'deal_survey_previous', 'label' => "DEAL, SURVEY\nPERIODE LALU", 'header' => 'headGreen', 'sub' => 'subHeadGreen', 'total' => 'totalGreen'],
        ['key' => 'deal_omset_current', 'label' => "DEAL, OMSET\nPERIODE INI", 'header' => 'headDarkGreen', 'sub' => 'subHeadDarkGreen', 'total' => 'totalDarkGreen'],
        ['key' => 'deal_omset_next', 'label' => "DEAL, OMSET\nPERIODE DEPAN", 'header' => 'headYellow', 'sub' => 'subHeadYellow', 'total' => 'totalYellow'],
    ];

    public function buildWorkbook(array $report): string
    {
        $options = $this->excelOptions($report['allowedAccounts'] ?? null);

        $worksheets = [
            $this->buildDataSheet($report, $options),
            $this->buildAnalysisSheet($report),
            $this->buildKlasemenSheet($report, hidden: true),
            $this->buildOptionsSheet($options),
        ];

        return $this->buildSpreadsheetXml($worksheets);
    }

    public function buildTemplateWorkbook(?User $user = null): string
    {
        $options = $this->excelOptions($this->accountsForUser($user));

        return $this->buildSpreadsheetXml([
            $this->buildDataTemplateSheet($options),
            $this->buildOptionsSheet($options),
        ]);
    }

    private function buildSpreadsheetXml(array $worksheets): string
    {
        $xml = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<?mso-application progid="Excel.Sheet"?>',
            '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"'
            . ' xmlns:o="urn:schemas-microsoft-com:office:office"'
            . ' xmlns:x="urn:schemas-microsoft-com:office:excel"'
            . ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"'
            . ' xmlns:html="http://www.w3.org/TR/REC-html40">',
            $this->stylesXml(),
            $this->namedRangesXml($worksheets),
        ];

        foreach ($worksheets as $worksheet) {
            $xml[] = sprintf('<Worksheet ss:Name="%s">', $this->escapeSheetName($worksheet['name']));
            $xml[] = '<Table x:FullColumns="1" x:FullRows="1">';

            foreach ($worksheet['columns'] as $width) {
                $xml[] = sprintf('<Column ss:AutoFitWidth="0" ss:Width="%s"/>', (float) $width);
            }

            foreach ($worksheet['rows'] as $row) {
                $height = isset($row['height']) ? sprintf(' ss:Height="%s"', (float) $row['height']) : '';
                $xml[] = sprintf('<Row%s>', $height);

                foreach ($row['cells'] as $cell) {
                    $xml[] = $this->buildCell($cell);
                }

                $xml[] = '</Row>';
            }

            $xml[] = '</Table>';
            $xml[] = $this->dataValidationsXml($worksheet['validations'] ?? []);
            $xml[] = $this->conditionalFormatsXml($worksheet['conditional_formats'] ?? []);
            $xml[] = $this->worksheetOptionsXml($worksheet['freeze_rows'] ?? 8, (bool) ($worksheet['hidden'] ?? false));
            $xml[] = '</Worksheet>';
        }

        $xml[] = '</Workbook>';

        return implode('', $xml);
    }

    private function buildKlasemenSheet(array $report, bool $hidden = false): array
    {
        $periodTitle = $report['periodTitle'] ?? strtoupper($report['periodLabel'] ?? '-');

        $rows = [
            $this->row([
                $this->cell('PC', 'pcHeader'),
                $this->cell('KLASEMEN AKUN', 'mainTitle', mergeAcross: 9),
            ], 32),
            $this->row([
                $this->cell('', 'pcHeader'),
                $this->cell('PUTRA CORPORATION - PERIODE ' . $periodTitle, 'mainSubtitle', mergeAcross: 9),
            ], 32),
            $this->blankRow(11, 18),
            $this->row([
                $this->cell('RD', 'metaStrong'),
                $this->cell($report['generatedDateLabel'] ?? $this->fullDate($report['generatedAt']), 'metaDate', mergeAcross: 1),
                $this->cell('', 'metaPlain'),
                $this->cell('', 'metaPlain'),
                $this->cell('', 'metaPlain'),
                $this->cell('', 'metaPlain'),
                $this->cell('', 'metaPlain'),
                $this->cell('', 'metaPlain'),
                $this->cell('', 'metaPlain'),
                $this->cell('', 'metaPlain'),
                $this->cell('', 'metaPlain'),
            ], 24),
            $this->row(collect([0, 1, 3, 6, 9, 12, 15, 18, 21, 24, 27])
                ->map(fn (int $value) => $this->cell($value, 'redMarker', 'Number'))
                ->all(), 14),
            $this->row([
                $this->cell('NO', 'headGray'),
                $this->cell('NAMA AKUN', 'headGray'),
                $this->cell('INTERAKSI', 'headGray'),
                $this->cell('SISA KONSUL', 'headYellow'),
                $this->cell('SURVEY', 'headBlue'),
                $this->cell('HOLD', 'headPeach'),
                $this->cell('CANCEL', 'headOrange'),
                $this->cell('DEAL, SURVEY PERIODE INI', 'headLightGreen'),
                $this->cell('DEAL, SURVEY PERIODE LALU', 'headGreen'),
                $this->cell('PROJEK DEAL PERIODE INI', 'headDarkGreen'),
                $this->cell('PROJEK DEAL PERIODE DEPAN', 'headYellow'),
            ], 52),
            $this->row([
                $this->cell('', 'headGray'),
                $this->cell('', 'headGray'),
                $this->cell('TOTAL', 'subHeadGray'),
                $this->cell('TOTAL', 'subHeadYellow'),
                $this->cell('TOTAL', 'subHeadBlue'),
                $this->cell('TOTAL', 'subHeadPeach'),
                $this->cell('TOTAL', 'subHeadOrange'),
                $this->cell('TOTAL', 'subHeadLightGreen'),
                $this->cell('TOTAL', 'subHeadGreen'),
                $this->cell('TOTAL', 'subHeadDarkGreen'),
                $this->cell('TOTAL', 'subHeadYellow'),
            ], 25),
            $this->blankRow(11, 12, 'separator'),
        ];

        foreach (($report['klasemenRows'] ?? collect())->values() as $index => $item) {
            $cells = [
                $this->cell($index + 1, 'bodyNo', 'Number'),
                $this->cell($item['account_name'] ?? '', 'bodyName'),
            ];

            foreach (self::METRIC_KEYS as $key) {
                $value = (int) ($item[$key] ?? 0);
                $cells[] = $this->cell($value, $value === 0 ? 'bodyZero' : 'bodyNumber', 'Number');
            }

            $rows[] = $this->row($cells, 20);
        }

        $rows[] = $this->blankRow(11, 20);

        $totalCells = [
            $this->cell('TOTAL', 'totalLabel', mergeAcross: 1),
        ];

        $totalStyles = [
            'totalGray',
            'totalYellow',
            'totalBlue',
            'totalPeach',
            'totalOrange',
            'totalLightGreen',
            'totalGreen',
            'totalDarkGreen',
            'totalYellow',
        ];

        foreach (self::METRIC_KEYS as $index => $key) {
            $totalCells[] = $this->cell((int) ($report['klasemenTotals'][$key] ?? 0), $totalStyles[$index], 'Number');
        }

        $rows[] = $this->row($totalCells, 54);

        return [
            'name' => 'Klasemen Akun',
            'columns' => self::KLASEMEN_COLUMNS,
            'rows' => $rows,
            'freeze_rows' => 8,
            'hidden' => $hidden,
        ];
    }

    private function buildSurveyorSheet(array $report): array
    {
        $periodTitle = $report['periodTitle'] ?? strtoupper($report['periodLabel'] ?? '-');
        $columnCount = count(self::SURVEYOR_COLUMNS);

        $rows = [
            $this->row([
                $this->cell('PC', 'surveyorPc'),
                $this->cell('KLASEMEN SURVEYOR', 'surveyorTitle', mergeAcross: $columnCount - 2),
            ], 26),
            $this->row([
                $this->cell('', 'surveyorPc'),
                $this->cell('PUTRA CORPORATION - PERIODE ' . $periodTitle, 'surveyorSubtitle', mergeAcross: $columnCount - 2),
            ], 25),
            $this->blankRow($columnCount, 17),
            $this->row(array_merge(
                [
                    $this->cell('RD', 'metaStrong'),
                    $this->cell($report['generatedDateLabel'] ?? $this->fullDate($report['generatedAt']), 'metaDate', mergeAcross: 1),
                ],
                array_fill(0, $columnCount - 3, $this->cell('', 'metaPlain'))
            ), 21),
            $this->row(collect(range(0, $columnCount - 1))
                ->map(fn (int $value) => $this->cell($value, 'redMarker', 'Number'))
                ->all(), 12),
        ];

        $groupHeader = [
            $this->cell('NO', 'headGray'),
            $this->cell('NAMA AKUN', 'headGray'),
        ];
        foreach (self::SURVEYOR_GROUPS as $group) {
            $groupHeader[] = $this->cell($group['label'], $group['header'], mergeAcross: 2);
        }
        $rows[] = $this->row($groupHeader, 44);

        $subHeader = [
            $this->cell('', 'headGray'),
            $this->cell('', 'headGray'),
        ];
        foreach (self::SURVEYOR_GROUPS as $group) {
            $subHeader[] = $this->cell('TOTAL', $group['sub']);
            $subHeader[] = $this->cell('DK', $group['sub']);
            $subHeader[] = $this->cell('LK', $group['sub']);
        }
        $rows[] = $this->row($subHeader, 22);
        $rows[] = $this->blankRow($columnCount, 10, 'separator');

        foreach (($report['surveyorRows'] ?? collect())->values() as $index => $item) {
            $cells = [
                $this->cell($index + 1, 'bodyNo', 'Number'),
                $this->cell($item['surveyor_name'] ?? '', 'bodyName'),
            ];

            foreach (self::SURVEYOR_GROUPS as $group) {
                foreach (['total', 'dk', 'lk'] as $segment) {
                    $value = (int) ($item[$group['key']][$segment] ?? 0);
                    $cells[] = $this->cell($value, $value === 0 ? 'bodyZero' : 'bodyNumber', 'Number');
                }
            }

            $rows[] = $this->row($cells, 16);
        }

        $rows[] = $this->blankRow($columnCount, 10);

        $totalTop = [
            $this->cell('TOTAL', 'totalLabel', mergeAcross: 1),
        ];
        foreach (self::SURVEYOR_GROUPS as $group) {
            $totalTop[] = $this->cell((int) ($report['surveyorTotals'][$group['key']]['total'] ?? 0), $group['total'], 'Number');
            $totalTop[] = $this->cell((int) ($report['surveyorTotals'][$group['key']]['dk'] ?? 0), $group['total'], 'Number');
            $totalTop[] = $this->cell((int) ($report['surveyorTotals'][$group['key']]['lk'] ?? 0), $group['total'], 'Number');
        }
        $rows[] = $this->row($totalTop, 28);

        $totalBottom = [
            $this->cell('', 'totalLabel', mergeAcross: 1),
        ];
        foreach (self::SURVEYOR_GROUPS as $group) {
            $totalBottom[] = $this->cell('', $group['total']);
            $totalBottom[] = $this->cell((int) ($report['surveyorTotals'][$group['key']]['total'] ?? 0), $group['total'], 'Number', mergeAcross: 1);
        }
        $rows[] = $this->row($totalBottom, 28);

        return [
            'name' => 'Klasemen Surveyor',
            'columns' => self::SURVEYOR_COLUMNS,
            'rows' => $rows,
            'freeze_rows' => 8,
        ];
    }

    private function buildDataSheet(array $report, array $options): array
    {
        $rows = $this->dataLeadsHeaderRows();

        $items = ($report['detailRows'] ?? collect())->values();

        foreach ($items as $index => $item) {
            $rows[] = $this->dataLeadsBodyRow($index + 1, $item);
        }

        $minimumRows = max(self::DATA_LEADS_TEMPLATE_ROWS, $items->count());

        for ($index = $items->count(); $index < $minimumRows; $index++) {
            $rows[] = $this->dataLeadsBodyRow($index + 1);
        }

        return [
            'name' => 'Data Leads',
            'columns' => self::DATA_LEADS_COLUMNS,
            'rows' => $rows,
            'freeze_rows' => 4,
            'validations' => $this->dataLeadsValidations($minimumRows, $options),
        ];
    }

    private function buildAnalysisSheet(array $report): array
    {
        $analysisRows = ($report['analysisRows'] ?? collect())->values();
        $prospectTotal = (int) ($report['analysisTotal'] ?? $analysisRows->sum('count'));
        $categoryTotal = (int) $analysisRows->sum('count');
        $totalPercentage = $prospectTotal > 0 ? $categoryTotal / $prospectTotal : 0;
        $periodTitle = $report['periodTitle'] ?? strtoupper($report['periodLabel'] ?? '-');

        $rows = [
            $this->row([
                $this->cell('Tabel Analisis Catatan & Alasan: ' . $periodTitle, 'analysisTitle', mergeAcross: 3),
            ], 18),
            $this->row([
                $this->cell('Nama Akun :', 'analysisMetaLabel'),
                $this->cell(strtoupper($report['selectedAccountName'] ?? 'Semua Akun'), 'analysisMetaValue', mergeAcross: 2),
            ], 18),
            $this->row([
                $this->cell('Total Prospek :', 'analysisMetaLabel'),
                $this->cell($prospectTotal, 'analysisMetaValueNumber', 'Number', mergeAcross: 2),
            ], 18),
            $this->blankRow(4, 16),
            $this->row([
                $this->cell('Kategori Alasan / Catatan', 'analysisHeader'),
                $this->cell('Detail Keterangan', 'analysisHeader'),
                $this->cell('Jumlah', 'analysisHeader'),
                $this->cell('Persentase', 'analysisHeader'),
            ], 18),
        ];

        foreach ($analysisRows as $item) {
            $rows[] = $this->row([
                $this->cell($item['category'] ?? '', 'analysisBodyBold'),
                $this->cell($item['detail'] ?? '', 'analysisBody'),
                $this->cell((int) ($item['count'] ?? 0), 'analysisBodyNumber', 'Number'),
                $this->cell((float) ($item['percentage'] ?? 0), 'analysisBodyPercent', 'Number'),
            ], 18);
        }

        $rows[] = $this->row([
            $this->cell('TOTAL', 'analysisBodyBold'),
            $this->cell('', 'analysisBody'),
            $this->cell($categoryTotal, 'analysisBodyNumber', 'Number'),
            $this->cell($totalPercentage, 'analysisBodyPercent', 'Number'),
        ], 18);

        return [
            'name' => 'Analisa Catatan',
            'columns' => self::ANALYSIS_COLUMNS,
            'rows' => $rows,
            'freeze_rows' => 5,
        ];
    }

    private function buildDataTemplateSheet(array $options): array
    {
        $rows = $this->dataLeadsHeaderRows();

        for ($index = 0; $index < self::DATA_LEADS_TEMPLATE_ROWS; $index++) {
            $rows[] = $this->dataLeadsBodyRow($index + 1);
        }

        return [
            'name' => 'Template Leads',
            'columns' => self::DATA_LEADS_COLUMNS,
            'rows' => $rows,
            'freeze_rows' => 4,
            'validations' => $this->dataLeadsValidations(self::DATA_LEADS_TEMPLATE_ROWS, $options),
            'conditional_formats' => $this->dataLeadsConditionalFormats(self::DATA_LEADS_TEMPLATE_ROWS),
        ];
    }

    private function buildOptionsSheet(array $options): array
    {
        $maxRows = max(
            count($options['accounts']),
            count($options['products']),
            count($options['statuses']),
            count($options['domiciles']),
            count($options['sequences']),
            count($options['city_province_rows']),
            count($options['city_province_lookup_rows']),
            count($options['city_domicile_lookup_rows']),
            1
        );

        $rows = [
            $this->row([
                $this->cell('Nama Akun', 'dataLeadHeader'),
                $this->cell('ID Akun', 'dataLeadHeader'),
                $this->cell('Jenis Kebutuhan', 'dataLeadHeader'),
                $this->cell('Kategori', 'dataLeadHeader'),
                $this->cell('Domisili', 'dataLeadHeader'),
                $this->cell('Sequence Key', 'dataLeadHeader'),
                $this->cell('Sequence Akun', 'dataLeadHeader'),
                $this->cell('Last Number', 'dataLeadHeader'),
                $this->cell('Kota/Kabupaten', 'dataLeadHeader'),
                $this->cell('Provinsi Mapping', 'dataLeadHeader'),
                $this->cell('Kota Lookup', 'dataLeadHeader'),
                $this->cell('Provinsi Lookup', 'dataLeadHeader'),
                $this->cell('Kota Domisili Lookup', 'dataLeadHeader'),
                $this->cell('Domisili Lookup', 'dataLeadHeader'),
            ], 22),
        ];

        for ($index = 0; $index < $maxRows; $index++) {
            $account = $options['accounts'][$index] ?? null;
            $sequence = $options['sequences'][$index] ?? null;
            $cityProvince = $options['city_province_rows'][$index] ?? null;
            $cityProvinceLookup = $options['city_province_lookup_rows'][$index] ?? null;
            $cityDomicileLookup = $options['city_domicile_lookup_rows'][$index] ?? null;

            $rows[] = $this->row([
                $this->cell($account['name'] ?? '', 'dataLeadBody'),
                $this->cell($account['id'] ?? '', 'dataLeadBody', ($account['id'] ?? '') !== '' ? 'Number' : 'String'),
                $this->cell($options['products'][$index] ?? '', 'dataLeadBody'),
                $this->cell($options['statuses'][$index] ?? '', 'dataLeadBody'),
                $this->cell($options['domiciles'][$index] ?? '', 'dataLeadBody'),
                $this->cell($sequence['key'] ?? '', 'dataLeadBody'),
                $this->cell($sequence['account_id'] ?? '', 'dataLeadBody', ($sequence['account_id'] ?? '') !== '' ? 'Number' : 'String'),
                $this->cell($sequence['last_number'] ?? '', 'dataLeadBody', ($sequence['last_number'] ?? '') !== '' ? 'Number' : 'String'),
                $this->cell($cityProvince['city'] ?? '', 'dataLeadBody'),
                $this->cell($cityProvince['province'] ?? '', 'dataLeadBody'),
                $this->cell($cityProvinceLookup['city'] ?? '', 'dataLeadBody'),
                $this->cell($cityProvinceLookup['province'] ?? '', 'dataLeadBody'),
                $this->cell($cityDomicileLookup['city'] ?? '', 'dataLeadBody'),
                $this->cell($cityDomicileLookup['domicile'] ?? '', 'dataLeadBody'),
            ], 18);
        }

        return [
            'name' => 'Opsi',
            'columns' => [190, 70, 190, 190, 105, 115, 95, 95, 200, 160, 200, 160, 200, 115],
            'rows' => $rows,
            'freeze_rows' => 1,
            'hidden' => true,
            'names' => [
                ['name' => 'AccountOptions', 'refers_to' => '=Opsi!R2C1:R' . max(2, count($options['accounts']) + 1) . 'C1'],
                ['name' => 'ProductOptions', 'refers_to' => '=Opsi!R2C3:R' . max(2, count($options['products']) + 1) . 'C3'],
                ['name' => 'StatusOptions', 'refers_to' => '=Opsi!R2C4:R' . max(2, count($options['statuses']) + 1) . 'C4'],
                ['name' => 'DomicileOptions', 'refers_to' => '=Opsi!R2C5:R' . max(2, count($options['domiciles']) + 1) . 'C5'],
                ['name' => 'CityOptions', 'refers_to' => '=Opsi!R2C9:R' . max(2, count($options['city_province_rows']) + 1) . 'C9'],
                ['name' => 'CityProvinceMap', 'refers_to' => '=Opsi!R2C9:R' . max(2, count($options['city_province_rows']) + 1) . 'C10'],
                ['name' => 'CityProvinceLookup', 'refers_to' => '=Opsi!R2C11:R' . max(2, count($options['city_province_lookup_rows']) + 1) . 'C12'],
                ['name' => 'CityDomicileLookup', 'refers_to' => '=Opsi!R2C13:R' . max(2, count($options['city_domicile_lookup_rows']) + 1) . 'C14'],
            ],
        ];
    }

    private function dataLeadsHeaderRows(): array
    {
        return [
            $this->row([
                $this->cell('', 'dataLeadNoteBlank'),
                $this->cell(
                    "Note:\n*Ikuti legenda warna di samping.\n*Gunakan pilihan dropdown jika tersedia.\n*Tanggal pakai format tanggal/bulan/tahun. Contoh: 07/05/2026.\n*Tips tanggal: bisa ketik 7/5 lalu Enter, 07/05 lalu Enter, atau 7/5/26 lalu Enter.",
                    'dataLeadNote',
                    mergeAcross: 3,
                    mergeDown: 1
                ),
                $this->cell('BIRU: boleh diubah, pilih dari dropdown', 'dataLeadBlueWrap', index: 6, mergeAcross: 1),
                $this->cell('PUTIH: boleh diisi manual', 'dataLeadBodyWrap', mergeAcross: 1),
                $this->cell('ORANGE: otomatis, jangan diubah', 'dataLeadAutoWrap', mergeAcross: 1),
            ], 36),
            $this->row([
                $this->cell('', 'dataLeadNoteBlank'),
                $this->cell('Contoh: Nama Akun, Jenis Kebutuhan, Kategori, Kota Kab.', 'dataLeadBlueWrap', index: 6, mergeAcross: 1),
                $this->cell('Contoh: Nama Konsumen, WA, Detail, Catatan', 'dataLeadBodyWrap', mergeAcross: 1),
                $this->cell('Contoh: NO, ID Konsumen, Domisili, Provinsi', 'dataLeadAutoWrap', mergeAcross: 1),
            ], 36),
            $this->row([
                $this->cell('NO', 'dataLeadHeader', mergeDown: 1),
                $this->cell('ID KONSUMEN', 'dataLeadHeader', mergeDown: 1),
                $this->cell('TGL. AWAL KONSUL', 'dataLeadHeader', mergeDown: 1),
                $this->cell('NAMA AKUN', 'dataLeadHeader', mergeDown: 1),
                $this->cell('DATA KONSUMEN', 'dataLeadHeader', mergeAcross: 4),
                $this->cell('KEBUTUHAN KATEGORI', 'dataLeadHeader', mergeAcross: 3),
                $this->cell('UPDATE', 'dataLeadHeader'),
            ], 21.75),
            $this->row([
                $this->cell('Nama Konsumen', 'dataLeadHeader', index: 5),
                $this->cell('WA Konsumen', 'dataLeadHeader'),
                $this->cell('Domisili', 'dataLeadHeader'),
                $this->cell('Provinsi', 'dataLeadHeader'),
                $this->cell('Kota Kab.', 'dataLeadHeader'),
                $this->cell('Jenis Kebutuhan', 'dataLeadHeader'),
                $this->cell('Detail Kebutuhan', 'dataLeadHeader'),
                $this->cell('Catatan', 'dataLeadHeader'),
                $this->cell('kategori', 'dataLeadHeader'),
                $this->cell('Tanggal Update', 'dataLeadHeader'),
            ], 24.75),
        ];
    }

    private function dataLeadsBodyRow(int $number, ?array $item = null): array
    {
        $hasData = $item !== null;
        $consultationId = $item['consultation_id'] ?? '';

        return $this->row([
            $hasData
                ? $this->cell($number, 'dataLeadAuto', 'Number')
                : $this->cell('', 'dataLeadAuto', formula: $this->rowNumberFormula()),
            $consultationId !== ''
                ? $this->cell($consultationId, 'dataLeadAuto')
                : $this->cell('', 'dataLeadAuto', formula: $this->consultationIdFormula()),
            ! empty($item['consultation_date_excel'])
                ? $this->cell($item['consultation_date_excel'], 'dataLeadDate', 'DateTime')
                : $this->cell('', 'dataLeadDate'),
            $this->cell($item['account'] ?? '', 'dataLeadBlue'),
            $this->cell($item['client_name'] ?? '', 'dataLeadBody'),
            $this->cell($item['phone'] ?? '', 'dataLeadPhone'),
            $this->cell($item['domicile'] ?? '', 'dataLeadAuto', formula: $this->domicileByCityFormula()),
            $this->cell($item['province'] ?? '', 'dataLeadAuto', formula: $this->provinceByCityFormula()),
            $this->cell($this->excelCityName($item['city'] ?? ''), 'dataLeadBlue'),
            $this->cell($item['need'] ?? '', 'dataLeadBlue'),
            $this->cell($item['product_details'] ?? '', 'dataLeadBodyWrap'),
            $this->cell($item['notes'] ?? '', 'dataLeadBodyWrap'),
            $this->cell($item['status'] ?? '', 'dataLeadBlue'),
            ! empty($item['updated_at_excel'])
                ? $this->cell($item['updated_at_excel'], 'dataLeadDate', 'DateTime')
                : $this->cell('', 'dataLeadDate'),
        ], 18);
    }

    private function accountsForUser(?User $user): ?Collection
    {
        if (! $user) {
            return null;
        }

        if ($user->isAdmin()) {
            return Account::query()
                ->whereKey($user->account_id)
                ->orderBy('name')
                ->get(['id', 'name']);
        }

        return Account::query()
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    private function excelOptions(?Collection $accounts = null): array
    {
        $accounts ??= Account::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        $accountRows = $accounts
            ->map(fn (Account $account) => [
                'id' => (int) $account->id,
                'name' => (string) $account->name,
            ])
            ->values()
            ->all();

        $products = NeedsCategory::query()
            ->forConsultationOptions()
            ->pluck('name')
            ->map(fn ($name) => (string) $name)
            ->values()
            ->all();

        $availableStatuses = StatusCategory::query()
            ->whereIn('name', self::LEAD_STATUS_OPTIONS)
            ->pluck('name')
            ->flip();

        $statuses = collect(self::LEAD_STATUS_OPTIONS)
            ->filter(fn (string $status) => $availableStatuses->has($status))
            ->values()
            ->all();

        $accountIds = collect($accountRows)->pluck('id')->all();
        $sequences = DB::table('consultation_sequences')
            ->when($accountIds !== [], fn ($query) => $query->whereIn('account_id', $accountIds))
            ->orderBy('account_id')
            ->orderBy('year_month')
            ->get(['account_id', 'year_month', 'last_number'])
            ->map(fn ($sequence) => [
                'key' => (string) ((int) $sequence->account_id) . (string) $sequence->year_month,
                'account_id' => (int) $sequence->account_id,
                'last_number' => (int) $sequence->last_number,
            ])
            ->values()
            ->all();

        $cityProvinceRows = collect(config('wilayah_kota.mapping', []))
            ->map(fn (string $province, string $city) => [
                'city' => $this->excelCityName($city),
                'province' => $province,
            ])
            ->sortBy([
                ['province', 'asc'],
                ['city', 'asc'],
            ], SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        $cityProvinceLookupRows = $this->cityProvinceLookupRows($cityProvinceRows);
        $cityDomicileLookupRows = $this->cityDomicileLookupRows($cityProvinceLookupRows);

        return [
            'accounts' => $accountRows,
            'products' => $products,
            'statuses' => $statuses,
            'domiciles' => ['Dalam Kota', 'Luar Kota'],
            'city_province_rows' => $cityProvinceRows,
            'city_province_lookup_rows' => $cityProvinceLookupRows,
            'city_domicile_lookup_rows' => $cityDomicileLookupRows,
            'sequences' => $sequences,
        ];
    }

    private function cityProvinceLookupRows(array $cityProvinceRows): array
    {
        $shortAliases = [
            'Jakarta Barat' => 'Jakbar',
            'Jakarta Pusat' => 'Jakpus',
            'Jakarta Selatan' => 'Jaksel',
            'Jakarta Timur' => 'Jaktim',
            'Jakarta Utara' => 'Jakut',
            'Bandung Barat' => 'KBB',
            'Tangerang Selatan' => 'Tangsel',
        ];

        return collect($cityProvinceRows)
            ->flatMap(function (array $row) use ($shortAliases) {
                $city = (string) $row['city'];
                $province = (string) $row['province'];
                $plainCity = preg_replace('/^(Kota Administrasi|Kabupaten Administrasi|Kota|Kabupaten|Kab\.)\s+/u', '', $city);
                $longCity = $this->fullCityName($city);
                $names = collect([$city, $plainCity]);

                if (str_starts_with($city, 'Kab. ')) {
                    $names->push(str_replace('Kab. ', 'Kab ', $city));
                }

                if ($longCity !== $city) {
                    $names->push($longCity);
                }

                if (isset($shortAliases[$plainCity])) {
                    $names->push($shortAliases[$plainCity]);
                }

                return $names
                    ->filter()
                    ->unique()
                    ->map(fn (string $name) => [
                        'city' => $name,
                        'province' => $province,
                    ]);
            })
            ->unique(fn (array $row) => mb_strtolower($row['city']))
            ->sortBy([
                ['province', 'asc'],
                ['city', 'asc'],
            ], SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    private function cityDomicileLookupRows(array $cityProvinceLookupRows): array
    {
        return collect($cityProvinceLookupRows)
            ->map(fn (array $row) => [
                'city' => (string) $row['city'],
                'domicile' => $this->isInsideBandungArea((string) $row['city']) ? 'Dalam Kota' : 'Luar Kota',
            ])
            ->unique(fn (array $row) => mb_strtolower($row['city']))
            ->sortBy('city', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    private function isInsideBandungArea(string $city): bool
    {
        $normalized = str($city)
            ->lower()
            ->replace(['.', '-', '_'], ' ')
            ->squish()
            ->toString();

        return in_array($normalized, [
            'bandung',
            'kota bandung',
            'kab bandung',
            'kabupaten bandung',
            'bandung barat',
            'kab bandung barat',
            'kabupaten bandung barat',
            'kbb',
            'cimahi',
            'kota cimahi',
        ], true);
    }

    private function excelCityName(?string $city): string
    {
        $city = trim((string) $city);
        $city = preg_replace('/^Kota Administrasi\s+/u', '', $city) ?? $city;
        $city = preg_replace('/^Kabupaten Administrasi\s+/u', '', $city) ?? $city;

        return preg_replace('/^Kabupaten\s+/u', 'Kab. ', $city) ?? $city;
    }

    private function fullCityName(string $city): string
    {
        return preg_replace('/^Kab\.\s+/u', 'Kabupaten ', $city) ?? $city;
    }

    private function dataLeadsValidations(int $rowCount, array $options): array
    {
        $lastRow = 4 + $rowCount;

        return [
            ['range' => "R5C4:R{$lastRow}C4", 'value' => '=AccountOptions'],
            ['range' => "R5C9:R{$lastRow}C9", 'value' => '=CityOptions'],
            ['range' => "R5C10:R{$lastRow}C10", 'value' => '=ProductOptions'],
            ['range' => "R5C13:R{$lastRow}C13", 'value' => '=StatusOptions'],
        ];
    }

    private function dataLeadsConditionalFormats(int $rowCount): array
    {
        $lastRow = 4 + $rowCount;

        return [
            [
                'range' => "R5C2:R{$lastRow}C2",
                'formula' => '=AND(RC<>"",COUNTIF(R5C2:R' . $lastRow . 'C2,RC)>1)',
                'style' => 'background-color:#FF0000;color:#FFFFFF;font-weight:700',
            ],
        ];
    }

    private function consultationIdFormula(): string
    {
        $accountId = 'VLOOKUP(RC[2],Opsi!R2C1:R1000C2,2,FALSE)';
        $currentMonthKey = $this->currentMonthKeyFormula();
        $monthRangeKey = $this->monthRangeKeyFormula();
        $sequenceKey = 'TEXT(' . $accountId . ',"0")&' . $currentMonthKey;
        $sequenceOffset = 'IFERROR(VLOOKUP(' . $sequenceKey . ',Opsi!R2C6:R5000C8,3,FALSE),0)';
        $pastedRowsInMonth = 'SUMPRODUCT((R5C4:RC[2]=RC[2])*(' . $monthRangeKey . '=' . $currentMonthKey . '))';

        return '=IF(OR(RC[2]="",RC[1]=""),"",IFERROR(TEXT(' . $accountId . ',"00")&"."&'
            . $currentMonthKey . '&"."&TEXT(' . $sequenceOffset . '+' . $pastedRowsInMonth . ',"0000"),""))';
    }

    private function rowNumberFormula(): string
    {
        return '=IF(OR(RC[4]="",RC[5]=""),"",COUNTA(R5C5:RC[4]))';
    }

    private function provinceByCityFormula(): string
    {
        return '=IF(RC[1]="","",IFERROR(VLOOKUP(RC[1],CityProvinceLookup,2,FALSE),""))';
    }

    private function domicileByCityFormula(): string
    {
        return '=IF(RC[2]="","",IFERROR(VLOOKUP(RC[2],CityDomicileLookup,2,FALSE),"Luar Kota"))';
    }

    private function currentMonthKeyFormula(): string
    {
        return 'IF(ISNUMBER(RC[1]),TEXT(RC[1],"yymm"),RIGHT(RC[1],2)&TEXT(MID(RC[1],FIND("/",RC[1])+1,FIND("/",RC[1],FIND("/",RC[1])+1)-FIND("/",RC[1])-1),"00"))';
    }

    private function monthRangeKeyFormula(): string
    {
        return 'IFERROR(IF(ISNUMBER(R5C3:RC[1]),TEXT(R5C3:RC[1],"yymm"),RIGHT(R5C3:RC[1],2)&TEXT(MID(R5C3:RC[1],FIND("/",R5C3:RC[1])+1,FIND("/",R5C3:RC[1],FIND("/",R5C3:RC[1])+1)-FIND("/",R5C3:RC[1])-1),"00")),"")';
    }

    private function row(array $cells, int|float|null $height = null): array
    {
        return ['cells' => $cells, 'height' => $height];
    }

    private function blankRow(int $columnCount, int|float|null $height = null, string $style = 'blank'): array
    {
        return $this->row([
            $this->cell('', $style, mergeAcross: $columnCount - 1),
        ], $height);
    }

    private function cell(
        mixed $value,
        ?string $style = null,
        string $type = 'String',
        ?int $mergeAcross = null,
        ?int $mergeDown = null,
        ?int $index = null,
        ?string $formula = null
    ): array {
        return [
            'value' => $value,
            'style' => $style,
            'type' => $type,
            'merge_across' => $mergeAcross,
            'merge_down' => $mergeDown,
            'index' => $index,
            'formula' => $formula,
        ];
    }

    private function buildCell(array $cell): string
    {
        $attributes = [];

        if (! empty($cell['style'])) {
            $attributes[] = sprintf('ss:StyleID="%s"', $cell['style']);
        }

        if (($cell['index'] ?? null) !== null) {
            $attributes[] = sprintf('ss:Index="%d"', (int) $cell['index']);
        }

        if (($cell['merge_across'] ?? null) !== null) {
            $attributes[] = sprintf('ss:MergeAcross="%d"', (int) $cell['merge_across']);
        }

        if (($cell['merge_down'] ?? null) !== null) {
            $attributes[] = sprintf('ss:MergeDown="%d"', (int) $cell['merge_down']);
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

    private function dataValidationsXml(array $validations): string
    {
        if ($validations === []) {
            return '';
        }

        return collect($validations)
            ->map(fn (array $validation) => '<DataValidation xmlns="urn:schemas-microsoft-com:office:excel">'
                . '<Range>' . htmlspecialchars($validation['range'], ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</Range>'
                . '<Type>List</Type>'
                . '<Value>' . htmlspecialchars($validation['value'], ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</Value>'
                . '</DataValidation>')
            ->implode('');
    }

    private function conditionalFormatsXml(array $formats): string
    {
        if ($formats === []) {
            return '';
        }

        return collect($formats)
            ->map(fn (array $format) => '<ConditionalFormatting xmlns="urn:schemas-microsoft-com:office:excel">'
                . '<Range>' . htmlspecialchars($format['range'], ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</Range>'
                . '<Condition>'
                . '<Value1>' . htmlspecialchars($format['formula'], ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</Value1>'
                . '<Format Style="' . htmlspecialchars($format['style'], ENT_XML1 | ENT_COMPAT, 'UTF-8') . '"/>'
                . '</Condition>'
                . '</ConditionalFormatting>')
            ->implode('');
    }

    private function namedRangesXml(array $worksheets): string
    {
        $names = collect($worksheets)
            ->flatMap(fn (array $worksheet) => $worksheet['names'] ?? [])
            ->values();

        if ($names->isEmpty()) {
            return '';
        }

        return '<Names>'
            . $names->map(fn (array $name) => sprintf(
                '<NamedRange ss:Name="%s" ss:RefersTo="%s"/>',
                htmlspecialchars($name['name'], ENT_XML1 | ENT_COMPAT, 'UTF-8'),
                htmlspecialchars($name['refers_to'], ENT_XML1 | ENT_COMPAT, 'UTF-8')
            ))->implode('')
            . '</Names>';
    }

    private function worksheetOptionsXml(int $freezeRows, bool $hidden = false): string
    {
        return '<WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">'
            . ($hidden ? '<Visible>SheetHidden</Visible>' : '')
            . '<FreezePanes/>'
            . '<FrozenNoSplit/>'
            . sprintf('<SplitHorizontal>%d</SplitHorizontal>', max(1, $freezeRows))
            . sprintf('<TopRowBottomPane>%d</TopRowBottomPane>', max(1, $freezeRows))
            . '<ActivePane>2</ActivePane>'
            . '<ProtectObjects>False</ProtectObjects>'
            . '<ProtectScenarios>False</ProtectScenarios>'
            . '</WorksheetOptions>';
    }

    private function escapeSheetName(string $name): string
    {
        $normalized = mb_substr(preg_replace('/[\\\\\\/?*\\[\\]:]/', '-', $name), 0, 31);

        return htmlspecialchars($normalized, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    private function fullDate($date): string
    {
        $days = [
            'Sunday' => 'Minggu',
            'Monday' => 'Senin',
            'Tuesday' => 'Selasa',
            'Wednesday' => 'Rabu',
            'Thursday' => 'Kamis',
            'Friday' => 'Jumat',
            'Saturday' => 'Sabtu',
        ];

        return sprintf(
            '%s, %s %s %s',
            $days[$date->format('l')] ?? $date->format('l'),
            $date->format('d'),
            $this->monthName((int) $date->format('n')),
            $date->format('Y')
        );
    }

    private function monthName(int $month): string
    {
        return [
            1 => 'Januari',
            2 => 'Februari',
            3 => 'Maret',
            4 => 'April',
            5 => 'Mei',
            6 => 'Juni',
            7 => 'Juli',
            8 => 'Agustus',
            9 => 'September',
            10 => 'Oktober',
            11 => 'November',
            12 => 'Desember',
        ][$month] ?? (string) $month;
    }

    private function stylesXml(): string
    {
        return '<Styles>'
            . '<Style ss:ID="Default" ss:Name="Normal"><Alignment ss:Vertical="Center"/><Font ss:FontName="Calibri" ss:Size="11" ss:Color="#111827"/><Interior ss:Color="#FFFFFF" ss:Pattern="Solid"/></Style>'
            . $this->style('pcHeader', '#EFEFEF', '#111827', true, 18, 'Center', 'Center', true, rotate: -90)
            . $this->style('mainTitle', '#F7F7F7', '#111827', true, 18, 'Center', 'Center')
            . $this->style('mainSubtitle', '#F7F7F7', '#111827', true, 16, 'Center', 'Center')
            . $this->style('surveyorPc', '#EFEFEF', '#111827', true, 10, 'Center', 'Center', true, rotate: -90)
            . $this->style('surveyorTitle', '#F7F7F7', '#111827', true, 13, 'Center', 'Center')
            . $this->style('surveyorSubtitle', '#F7F7F7', '#111827', true, 11, 'Center', 'Center')
            . $this->style('metaStrong', '#FFFFFF', '#000000', true, 9, 'Center', 'Center')
            . $this->style('metaDate', '#FFFFFF', '#000000', true, 9, 'Left', 'Center')
            . $this->style('metaPlain', '#FFFFFF', '#000000', false, 9, 'Left', 'Center')
            . $this->style('redMarker', '#FFFFFF', '#FF0000', false, 7, 'Center', 'Center')
            . $this->style('headGray', '#D9D9D9', '#111827', true, 10, 'Center', 'Center', true)
            . $this->style('headYellow', '#FFD966', '#111827', true, 10, 'Center', 'Center', true)
            . $this->style('headBlue', '#BDD7EE', '#111827', true, 10, 'Center', 'Center', true)
            . $this->style('headPeach', '#F8CBAD', '#111827', true, 10, 'Center', 'Center', true)
            . $this->style('headOrange', '#F4B183', '#111827', true, 10, 'Center', 'Center', true)
            . $this->style('headLightGreen', '#E2F0D9', '#111827', true, 10, 'Center', 'Center', true)
            . $this->style('headGreen', '#C6E0B4', '#111827', true, 10, 'Center', 'Center', true)
            . $this->style('headDarkGreen', '#A9D18E', '#111827', true, 10, 'Center', 'Center', true)
            . $this->style('subHeadGray', '#D9D9D9', '#111827', true, 10, 'Center', 'Center')
            . $this->style('subHeadYellow', '#FFD966', '#111827', true, 10, 'Center', 'Center')
            . $this->style('subHeadBlue', '#BDD7EE', '#111827', true, 10, 'Center', 'Center')
            . $this->style('subHeadPeach', '#F8CBAD', '#111827', true, 10, 'Center', 'Center')
            . $this->style('subHeadOrange', '#F4B183', '#111827', true, 10, 'Center', 'Center')
            . $this->style('subHeadLightGreen', '#E2F0D9', '#111827', true, 10, 'Center', 'Center')
            . $this->style('subHeadGreen', '#C6E0B4', '#111827', true, 10, 'Center', 'Center')
            . $this->style('subHeadDarkGreen', '#A9D18E', '#111827', true, 10, 'Center', 'Center')
            . $this->style('separator', '#D9D9D9', '#111827', false, 8, 'Center', 'Center')
            . $this->style('bodyNo', '#FFFFFF', '#000000', true, 9, 'Center', 'Center')
            . $this->style('bodyName', '#FFFFFF', '#000000', true, 9, 'Left', 'Center')
            . $this->style('bodyNumber', '#FFFFFF', '#000000', false, 10, 'Center', 'Center', numberFormat: '#,##0')
            . $this->style('bodyZero', '#FFFFFF', '#B7B7B7', false, 10, 'Center', 'Center', numberFormat: '#,##0')
            . $this->style('totalLabel', '#D9D9D9', '#111827', true, 15, 'Center', 'Center')
            . $this->style('totalGray', '#D9D9D9', '#111827', true, 15, 'Center', 'Center', numberFormat: '#,##0')
            . $this->style('totalYellow', '#FFD966', '#111827', true, 15, 'Center', 'Center', numberFormat: '#,##0')
            . $this->style('totalBlue', '#BDD7EE', '#111827', true, 15, 'Center', 'Center', numberFormat: '#,##0')
            . $this->style('totalPeach', '#F8CBAD', '#111827', true, 15, 'Center', 'Center', numberFormat: '#,##0')
            . $this->style('totalOrange', '#F4B183', '#111827', true, 15, 'Center', 'Center', numberFormat: '#,##0')
            . $this->style('totalLightGreen', '#E2F0D9', '#111827', true, 15, 'Center', 'Center', numberFormat: '#,##0')
            . $this->style('totalGreen', '#C6E0B4', '#111827', true, 15, 'Center', 'Center', numberFormat: '#,##0')
            . $this->style('totalDarkGreen', '#A9D18E', '#111827', true, 15, 'Center', 'Center', numberFormat: '#,##0')
            . $this->style('dataTitle', '#E8F1FF', '#0F172A', true, 16, 'Center', 'Center')
            . $this->style('dataSubtitle', '#F8FBFF', '#475569', false, 10, 'Left', 'Center', true)
            . $this->style('dataHeader', '#1D4ED8', '#FFFFFF', true, 9, 'Center', 'Center', true)
            . $this->style('dataCell', '#FFFFFF', '#0F172A', false, 9, 'Left', 'Top', true)
            . $this->style('dataCellAlt', '#F8FAFC', '#0F172A', false, 9, 'Left', 'Top', true)
            . $this->style('dataCellWrap', '#FFFFFF', '#0F172A', false, 9, 'Left', 'Top', true)
            . $this->style('dataCellWrapAlt', '#F8FAFC', '#0F172A', false, 9, 'Left', 'Top', true)
            . $this->style('dataCellCenter', '#FFFFFF', '#0F172A', false, 9, 'Center', 'Center')
            . $this->style('dataCellCenterAlt', '#F8FAFC', '#0F172A', false, 9, 'Center', 'Center')
            . $this->style('dateCell', '#FFFFFF', '#0F172A', false, 9, 'Center', 'Center', numberFormat: 'dd/mm/yyyy')
            . $this->style('dateCellAlt', '#F8FAFC', '#0F172A', false, 9, 'Center', 'Center', numberFormat: 'dd/mm/yyyy')
            . $this->style('dateTimeCell', '#FFFFFF', '#0F172A', false, 9, 'Center', 'Center', numberFormat: 'dd/mm/yyyy hh:mm')
            . $this->style('dateTimeCellAlt', '#F8FAFC', '#0F172A', false, 9, 'Center', 'Center', numberFormat: 'dd/mm/yyyy hh:mm')
            . $this->style('emptyState', '#F8FAFC', '#64748B', false, 10, 'Center', 'Center', true)
            . '<Style ss:ID="dataLeadNoteBlank"><Font ss:FontName="Calibri" ss:Size="11" ss:Color="#000000"/><Interior ss:Color="#FFFFFF" ss:Pattern="Solid"/></Style>'
            . '<Style ss:ID="dataLeadNote"><Alignment ss:Horizontal="Left" ss:Vertical="Top" ss:WrapText="1"/><Font ss:FontName="Calibri" ss:Size="11" ss:Color="#000000"/><Interior ss:Color="#FFFFFF" ss:Pattern="Solid"/></Style>'
            . $this->style('dataLeadHeader', '#A8D08E', '#000000', true, 12, 'Center', 'Center', borderWeight: 2)
            . $this->style('dataLeadHeaderBlue', '#6699FF', '#000000', true, 12, 'Center', 'Center', borderWeight: 2)
            . $this->style('dataLeadAuto', '#FFC000', '#000000', true, 11, 'Center', 'Center')
            . $this->style('dataLeadAutoWrap', '#FFC000', '#000000', true, 11, 'Center', 'Center', true)
            . $this->style('dataLeadBody', '#FFFFFF', '#000000', true, 11, 'Center', 'Center')
            . $this->style('dataLeadBodyWrap', '#FFFFFF', '#000000', true, 11, 'Center', 'Center', true)
            . $this->style('dataLeadPhone', '#FFFFFF', '#000000', true, 11, 'Center', 'Center', numberFormat: '@')
            . $this->style('dataLeadBlue', '#6699FF', '#000000', true, 11, 'Center', 'Center')
            . $this->style('dataLeadBlueWrap', '#6699FF', '#000000', true, 11, 'Center', 'Center', true)
            . $this->style('dataLeadDate', '#FFFFFF', '#000000', true, 11, 'Center', 'Center', numberFormat: 'dd/mm/yyyy')
            . $this->style('analysisTitle', '#FFFFFF', '#000000', true, 14, 'Left', 'Center', border: false)
            . $this->style('analysisMetaLabel', '#FFFFFF', '#000000', true, 14, 'Left', 'Center', border: false)
            . $this->style('analysisMetaValue', '#FFFFFF', '#000000', true, 14, 'Left', 'Center', border: false)
            . $this->style('analysisMetaValueNumber', '#FFFFFF', '#000000', true, 14, 'Left', 'Center', border: false, numberFormat: '#,##0')
            . $this->style('analysisHeader', '#C6E0B4', '#000000', true, 12, 'Center', 'Center', borderWeight: 2)
            . $this->style('analysisBodyBold', '#FFFFFF', '#000000', true, 12, 'Center', 'Center', borderWeight: 2)
            . $this->style('analysisBody', '#FFFFFF', '#000000', false, 12, 'Center', 'Center', borderWeight: 2)
            . $this->style('analysisBodyNumber', '#FFFFFF', '#000000', false, 12, 'Center', 'Center', numberFormat: '#,##0', borderWeight: 2)
            . $this->style('analysisBodyPercent', '#FFFFFF', '#000000', true, 12, 'Center', 'Center', numberFormat: '0.00%', borderWeight: 2)
            . '<Style ss:ID="blank"><Interior ss:Color="#FFFFFF" ss:Pattern="Solid"/></Style>'
            . '</Styles>';
    }

    private function style(
        string $id,
        string $background,
        string $color,
        bool $bold,
        int $size,
        string $horizontal,
        string $vertical,
        bool $wrap = false,
        ?string $numberFormat = null,
        ?int $rotate = null,
        int $borderWeight = 1,
        bool $border = true
    ): string {
        $alignment = sprintf(
            '<Alignment ss:Horizontal="%s" ss:Vertical="%s"%s%s/>',
            $horizontal,
            $vertical,
            $wrap ? ' ss:WrapText="1"' : '',
            $rotate !== null ? sprintf(' ss:Rotate="%d"', $rotate) : ''
        );

        $borders = $border
            ? sprintf(
                '<Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="%d" ss:Color="#000000"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="%d" ss:Color="#000000"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="%d" ss:Color="#000000"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="%d" ss:Color="#000000"/></Borders>',
                $borderWeight,
                $borderWeight,
                $borderWeight,
                $borderWeight
            )
            : '';

        return sprintf(
            '<Style ss:ID="%s">%s%s<Font ss:FontName="Calibri" ss:Size="%d" ss:Color="%s"%s/><Interior ss:Color="%s" ss:Pattern="Solid"/>%s</Style>',
            $id,
            $alignment,
            $borders,
            $size,
            $color,
            $bold ? ' ss:Bold="1"' : '',
            $background,
            $numberFormat ? sprintf('<NumberFormat ss:Format="%s"/>', htmlspecialchars($numberFormat, ENT_XML1 | ENT_COMPAT, 'UTF-8')) : ''
        );
    }
}
